<?php

declare(strict_types=1);

namespace MoveCloser\Magento2ConnectorOverride\Connector\Writer;

use Akeneo\Tool\Component\Batch\Item\DataInvalidItem;
use Webkul\Magento2Bundle\Component\Normalizer\PropertiesNormalizer;
use Webkul\Magento2Bundle\Connector\Writer\ProductWriter;

/**
 * Product writer override that stops the connector from self-poisoning the
 * option mapping cache during product export.
 *
 * Vanilla \Webkul\Magento2Bundle\Connector\Writer\ProductWriter::modifyOptionValues(),
 * when it cannot find an option mapping for a select/multiselect value, does two
 * harmful things:
 *   1. sends the raw Akeneo option code as the attribute value (Magento cannot
 *      match it → product gets a wrong/empty attribute),
 *   2. writes a corrupt mapping row with externalId = the code itself, which
 *      later makes the option export POST instead of PUT →
 *      "Admin store attribute option label "%1" is already exists.".
 *
 * This override resolves only the values that actually have a mapping. Unmapped
 * values are skipped (never sent, never used to create a junk mapping) and
 * reported once per (attribute, option) per run, so the gap is fixed at the
 * source instead of being papered over. Reconcile the missing mappings with
 * `magento2:reconcile-option-mappings` and re-run the product export.
 *
 * A SKU mapped to a localizable attribute that has no value for a store view's locale makes the
 * vanilla writer send the raw value array as the SKU and die with a TypeError. Such a product is
 * reported as a job warning and skipped in that store view, or in all of them when the default
 * locale has no SKU.
 *
 * @author MoveCloser
 */
class ContextAwareProductWriter extends ProductWriter
{
    private const SKIP_STORE_VIEW = '__skip_store_view';

    /**
     * @var array<string, true> de-duplicates missing-option warnings within a run
     */
    private array $loggedMissingOptions = [];

    /**
     * @var array<string, true> de-duplicates missing-SKU warnings within a run
     */
    private array $loggedMissingSkus = [];

    /**
     * @var array<string, mixed> last addProduct() result per localized SKU within one write()
     */
    private array $lastResults = [];

    /**
     * {@inheritdoc}
     *
     * Items whose SKU has no value for the default store view are reported and dropped before the
     * vanilla loop, which cannot create a product without its default-scope SKU.
     *
     * @param list<array<string, mixed>> $items
     */
    public function write(array $items)
    {
        $this->lastResults = [];
        $kept = [];

        foreach ($items as $item) {
            $skip = $this->missingDefaultSku($item);

            if (null === $skip) {
                $kept[] = $item;

                continue;
            }

            $this->warnMissingSku($skip, null);
            $this->stepExecution->incrementSummaryInfo('skipped');
        }

        return parent::write($kept);
    }

    /**
     * {@inheritdoc}
     *
     * With no SKU for this locale the item is formatted with its default-store-view SKU, which keeps
     * the vanilla post-loop linking and mapping valid, and marked so addProduct() never sends it.
     *
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    protected function formatData($item, $channel, $locale, $storeViewCurrency)
    {
        if (!is_array($item['sku'] ?? null)) {
            return parent::formatData($item, $channel, $locale, $storeViewCurrency);
        }

        $sku = $this->resolvedSku($item['sku'], $channel, $locale, $storeViewCurrency);

        if (null !== $sku) {
            $this->lastResults[$sku] ??= null;

            return parent::formatData($item, $channel, $locale, $storeViewCurrency);
        }

        $skip = $this->skipInfo($item, $locale);
        $defaultSku = $this->resolvedSku($item['sku'], $this->defaultChannel, $this->defaultLocale, null);

        // reached only from subclass paths outside write(); they fall back to write(), which drops the item
        if (null === $defaultSku) {
            return [self::SKIP_STORE_VIEW => $skip];
        }

        $item['sku'] = $defaultSku;
        $formatted = parent::formatData($item, $channel, $locale, $storeViewCurrency);
        $formatted[self::SKIP_STORE_VIEW] = $skip;

        return $formatted;
    }

    /**
     * {@inheritdoc}
     *
     * Last-resort name fallback. The connector requires the default locale (pl_PL) in every job, but a
     * product may have no pl_PL data at all - and even the configured fallback name attribute is empty
     * for that locale - so Magento rejects the save with "The Product Name attribute value is empty".
     * When the name is still empty at this point, fall back to the SKU so the product saves cleanly
     * (the SKU is the PIM "ID (NIE EDYTOWAC)" field, always present and unique). The pl_PL name is
     * never shown for products that are not in the PL website, and real per-store names still win.
     *
     * A product marked by formatData() is not sent; the last result for its SKU stands in for the
     * vanilla post-loop, which reads only the last store view's result.
     *
     * @param array<string, mixed> $productData
     * @param mixed                $parent
     * @param string               $storeViewCode
     */
    protected function addProduct($productData, $parent, $storeViewCode)
    {
        $product = $productData[self::AKENEO_ENTITY_NAME] ?? null;

        if (is_array($product) && isset($product[self::SKIP_STORE_VIEW])) {
            $sku = $product['sku'] ?? null;

            if (null === $sku) {
                return ['error' => 'no SKU for the default store view'];
            }

            $this->warnMissingSku($product[self::SKIP_STORE_VIEW], (string) $storeViewCode);

            return $this->lastResults[$sku] ?? ['error' => sprintf('no SKU for store view "%s"', $storeViewCode)];
        }

        if (
            is_array($product)
            && empty($product['name'])
            && !empty($product['sku'])
        ) {
            $productData[self::AKENEO_ENTITY_NAME]['name'] = $product['sku'];
        }

        $result = parent::addProduct($productData, $parent, $storeViewCode);

        if (is_array($product) && is_scalar($product['sku'] ?? null) && array_key_exists((string) $product['sku'], $this->lastResults)) {
            $this->lastResults[(string) $product['sku']] = $result;
        }

        return $result;
    }

    /**
     * Same truthiness as the vanilla formatData(), which drops a falsy SKU and falls back to the raw array.
     */
    private function resolvedSku(mixed $value, mixed $channel, mixed $locale, mixed $currency): ?string
    {
        $sku = $this->formatValueForMagento($value, $channel, $locale, $currency);

        return is_scalar($sku) && $sku ? (string) $sku : null;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array{identifier: string, attribute: string, locale: string}|null
     */
    private function missingDefaultSku(array $item): ?array
    {
        if (
            is_array($item['sku'] ?? null)
            && null === $this->resolvedSku($item['sku'], $this->defaultChannel, $this->defaultLocale, null)
        ) {
            return $this->skipInfo($item, (string) $this->defaultLocale);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array{identifier: string, attribute: string, locale: string}
     */
    private function skipInfo(array $item, mixed $locale): array
    {
        return [
            'identifier' => (string) ($item[PropertiesNormalizer::FIELD_META_DATA][PropertiesNormalizer::FIELD_IDENTIFIER] ?? $item['code'] ?? '?'),
            'attribute'  => (string) ($this->changeAttributeMappingsBasedOnProductType($item['type_id'] ?? null)['sku'] ?? 'sku'),
            'locale'     => (string) $locale,
        ];
    }

    /**
     * @param array{identifier: string, attribute: string, locale: string} $skip
     * @param string|null                                                  $storeViewCode null: all store views
     */
    private function warnMissingSku(array $skip, ?string $storeViewCode): void
    {
        $key = $skip['identifier'] . '|' . ($storeViewCode ?? '*');

        if (isset($this->loggedMissingSkus[$key])) {
            return;
        }

        $this->loggedMissingSkus[$key] = true;
        $where = null === $storeViewCode ? 'in any store view' : sprintf('to store view "%s"', $storeViewCode);

        $this->stepExecution->addWarning(
            sprintf(
                'Product "%s" not exported %s: SKU attribute "%s" has no value for locale %s.',
                $skip['identifier'],
                $where,
                $skip['attribute'],
                $skip['locale']
            ),
            [],
            new DataInvalidItem($skip + ['store_view' => $storeViewCode ?? 'all'])
        );
    }

    /**
     * {@inheritdoc}
     *
     * The fetched product is read only to carry a configurable's existing child links into the PUT,
     * so every other type skips that GET. The store-view unsets mirror the parent.
     *
     * @param array<string, mixed> $productData
     * @param string               $storeViewCode
     * @param mixed                $parent
     *
     * @return array<string, mixed>
     */
    protected function checkProductAndModifyData($productData, $storeViewCode, $parent = null)
    {
        if (($productData[self::AKENEO_ENTITY_NAME]['type_id'] ?? null) === 'configurable') {
            return parent::checkProductAndModifyData($productData, $storeViewCode, $parent);
        }

        if ('all' !== $storeViewCode) {
            unset(
                $productData[self::AKENEO_ENTITY_NAME]['tier_prices'],
                $productData[self::AKENEO_ENTITY_NAME]['extension_attributes']['downloadable_product_links'],
                $productData[self::AKENEO_ENTITY_NAME]['extension_attributes']['downloadable_product_samples']
            );
        }

        return $productData;
    }

    /**
     * {@inheritdoc}
     *
     * A store view sharing locale, channel and currency with "all" would get the same payload again: a full
     * product save that leaves only url_key copies and empty date rows in the store view. Product export
     * skips it; the category export still needs that store view.
     *
     * @param array<string, array<string, mixed>> $storeMappings
     * @param list<string>                        $locales
     * @param list<string>                        $channels
     *
     * @return array<string, array<string, mixed>>
     */
    protected function updateStoreMappingValueByLocalesChannels(array $storeMappings, array $locales, array $channels)
    {
        $storeMappings = parent::updateStoreMappingValueByLocalesChannels($storeMappings, $locales, $channels);
        $all = $storeMappings[self::DEFAULT_STORE_VIEW_CODE] ?? null;

        if (null === $all) {
            return $storeMappings;
        }

        $allCurrency = $this->effectiveCurrency('all', $all);

        foreach ($storeMappings as $storeViewCode => $mapping) {
            if (self::DEFAULT_STORE_VIEW_CODE === $storeViewCode) {
                continue;
            }

            $currency = $this->effectiveCurrency((string) $storeViewCode, $mapping);

            if (
                ($mapping['locale'] ?? null) === $all['locale']
                && ($mapping['channel'] ?? null) === $all['channel']
                && null !== $currency
                && $currency === $allCurrency
            ) {
                unset($storeMappings[$storeViewCode]);
            }
        }

        return $storeMappings;
    }

    /**
     * Same resolution as write(): the mapped currency, else the store view's base currency.
     *
     * @param array<string, mixed> $mapping
     */
    private function effectiveCurrency(string $storeViewCode, array $mapping): ?string
    {
        if (!empty($mapping['currency'])) {
            return (string) $mapping['currency'];
        }

        $baseCurrency = $this->storeSettings[$storeViewCode]['base_currency_code'] ?? null;

        return empty($baseCurrency) ? null : (string) $baseCurrency;
    }

    /**
     * {@inheritdoc}
     *
     * Mirrors the parent implementation for date/simple attributes. The select/multiselect branch is
     * hardened against unmapped options; the metric branch takes its axis codes from `custom_fields`
     * instead of attributesAxesOptions(), which hydrates every product in the PIM to return them.
     *
     * @param array<int, array{attribute_code: string, value: mixed}> $data
     * @param array<string, string>                                   $attributeMappings
     *
     * @return list<array<string, mixed>>
     */
    protected function modifyOptionValues($data, array $attributeMappings)
    {
        foreach ($data as $index => $attr) {
            $realAttrCode = !empty($attributeMappings[$attr['attribute_code']]) ? $attributeMappings[$attr['attribute_code']] : $attr['attribute_code'];
            $attribute = $this->attributeRepo->findOneByIdentifier($realAttrCode);

            if (empty($attribute)) {
                continue;
            }

            if ('pim_catalog_date' == $attribute->getType()) {
                $data[$index]['value'] = $this->formatDate($attr['value']);
            } elseif (in_array($attribute->getType(), $this->simpleAttributeTypes)) {
                // value passed through unchanged, like the parent implementation
            } elseif (in_array($attribute->getType(), $this->selectAttributeTypes)) {
                $missing = [];

                if (is_array($attr['value'])) {
                    $resolvedIds = [];

                    foreach ($attr['value'] as $singleValue) {
                        $attributeOption = $this->getMappingByCode($singleValue . '(' . $attribute->getCode() . ')', 'option');

                        if ($attributeOption) {
                            $resolvedIds[] = $attributeOption->getExternalId();
                        } else {
                            $missing[] = $singleValue;
                        }
                    }

                    if ($this->magentoVersion < '2.3') {
                        $data[$index]['value'] = $resolvedIds;
                        $hasValue = !empty($resolvedIds);
                    } else {
                        $data[$index]['value'] = implode(',', $resolvedIds);
                        $hasValue = '' !== $data[$index]['value'];
                    }

                    if (!$hasValue) {
                        $data[$index] = [];
                    }
                } elseif ('string' === gettype($attr['value'])) {
                    $attributeOption = $this->getMappingByCode($attr['value'] . '(' . $attribute->getCode() . ')', 'option');

                    if ($attributeOption) {
                        $data[$index]['value'] = $attributeOption->getExternalId();
                    } else {
                        $missing[] = $attr['value'];
                        $data[$index] = [];
                    }
                }

                if ($missing) {
                    $this->warnMissingOptions($attribute->getCode(), $missing);
                }
            } elseif ('pim_catalog_metric' == $attribute->getType() && isset($this->otherSettings['metric_is_active']) && filter_var($this->otherSettings['metric_is_active'], FILTER_VALIDATE_BOOLEAN)) {
                if (in_array($data[$index]['attribute_code'], $this->connectorService->getOtherMappings()['custom_fields'] ?? [])) {
                    $attributeOption = $this->getMappingByCode(($data[$index]['value']) . '(' . $data[$index]['attribute_code'] . ')', 'option');
                    $value = $data[$index]['value'] ?? 0;

                    if ($attributeOption) {
                        $value = $attributeOption->getExternalId();
                    }

                    $data[$index] = [
                        'attribute_code' => $attr['attribute_code'],
                        'value'          => $value,
                    ];
                }
            }

            if (!empty($data[$index])) {
                $data[$index]['attribute_code'] = strtolower($data[$index]['attribute_code']);
            }
        }

        return array_values(array_filter($data, static fn ($entry): bool => !empty($entry)));
    }

    /**
     * Reports unmapped option values once per (attribute, value) per run so a
     * genuine mapping gap is surfaced without flooding the report.
     *
     * @param list<string> $missingValues
     */
    private function warnMissingOptions(string $attributeCode, array $missingValues): void
    {
        $unreported = [];

        foreach ($missingValues as $value) {
            $key = $attributeCode . '|' . $value;

            if (!isset($this->loggedMissingOptions[$key])) {
                $this->loggedMissingOptions[$key] = true;
                $unreported[] = $value;
            }
        }

        if (!$unreported || !isset($this->stepExecution)) {
            return;
        }

        $this->stepExecution->addWarning(
            sprintf('Skipped unmapped option value(s) for attribute "%s": %s. Run magento2:reconcile-option-mappings.', $attributeCode, implode(', ', $unreported)),
            [],
            new DataInvalidItem([
                'attribute' => $attributeCode,
                'missing'   => $unreported,
            ])
        );
    }
}

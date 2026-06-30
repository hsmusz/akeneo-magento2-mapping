<?php

declare(strict_types=1);

namespace MoveCloser\Magento2ConnectorOverride\Connector\Writer;

use Akeneo\Tool\Component\Batch\Item\DataInvalidItem;
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
 * @author MoveCloser
 */
class ContextAwareProductWriter extends ProductWriter
{
    /**
     * @var array<string, true> de-duplicates missing-option warnings within a run
     */
    private array $loggedMissingOptions = [];

    /**
     * {@inheritdoc}
     *
     * Mirrors the parent implementation for date/simple/metric attributes; only
     * the select/multiselect branch is hardened against unmapped options.
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
                if (in_array($data[$index]['attribute_code'], array_keys($this->connectorService->attributesAxesOptions()))) {
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

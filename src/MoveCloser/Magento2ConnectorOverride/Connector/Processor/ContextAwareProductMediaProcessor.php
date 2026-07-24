<?php

declare(strict_types=1);

namespace MoveCloser\Magento2ConnectorOverride\Connector\Processor;

use Webkul\Magento2Bundle\Connector\Processor\ProductMediaProcessor;

/**
 * Media processor override that emits one gallery entry PER LOCALE for localized
 * image attributes, so a variant can carry a different image per language.
 *
 * Vanilla \Webkul\Magento2Bundle\Connector\Processor\ProductMediaProcessor
 * collapses a localizable image attribute to a single entry — it only reads
 * $value[0]['data'] (the first locale) in convertRelativeUrlToBase64() — so the
 * per-language images from Akeneo never reach Magento.
 *
 * This override detects image attributes that are genuinely localized (more than
 * one locale, with different files) and produces one entry per locale, each
 * tagged with meta.locale. The paired writer
 * (\MoveCloser\Magento2ConnectorOverride\Connector\Writer\ContextAwareProductMediaWriter)
 * then uploads all of them globally and flips the per-store-view `disabled` flag
 * so each store view exposes only its own image (read on the front via GraphQL
 * `media_gallery` — the base-image role stays global in this Magento).
 *
 * Non-localized images (single locale, or same file across locales, or
 * non-localizable attributes) fall straight through to the parent behaviour, so
 * nothing else in the catalog changes.
 *
 * @author MoveCloser
 */
class ContextAwareProductMediaProcessor extends ProductMediaProcessor
{
    /**
     * Extra per-locale entries produced while processing the current item,
     * flushed into the result media_gallery_entries by {@see self::process()}.
     *
     * @var list<array<string, mixed>>
     */
    private array $pendingLocalizedEntries = [];

    /**
     * {@inheritdoc}
     */
    public function process($product, $recursiveCall = false): array
    {
        if (!$recursiveCall) {
            $this->pendingLocalizedEntries = [];
        }

        $result = parent::process($product, $recursiveCall);

        if (is_array($result) && !empty($this->pendingLocalizedEntries) && isset($result['media_gallery_entries'])) {
            $position = count($result['media_gallery_entries']);

            foreach ($this->pendingLocalizedEntries as $entry) {
                $entry['position'] = $position++;
                $result['media_gallery_entries'][] = $entry;
            }

            $this->pendingLocalizedEntries = [];
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     *
     * For a localized image value (>1 locale, different files) builds one gallery
     * entry per locale: returns the default-locale entry (which keeps the image
     * roles, exactly like the parent) and stashes the remaining locales in
     * {@see self::$pendingLocalizedEntries}. Every produced entry carries
     * meta.locale so the writer can scope its visibility per store view.
     *
     * Any other value (string, single locale, identical files, video image) is
     * delegated verbatim to the parent implementation.
     *
     * @param mixed  $entry
     * @param mixed  $mediaAltText
     * @param int    $position
     * @param array  $imageRoles
     * @param mixed  $mediaAttribute
     * @param bool   $flag
     * @param bool   $disable
     */
    protected function convertRelativeUrlToBase64($entry, $mediaAltText = '', $position = 0, $imageRoles = [], $mediaAttribute = null, $flag = false, $disable = false)
    {
        $localeValues = $this->extractLocalizedValues($entry);

        if (null === $localeValues) {
            return parent::convertRelativeUrlToBase64($entry, $mediaAltText, $position, $imageRoles, $mediaAttribute, $flag, $disable);
        }

        $default = null;

        foreach (array_values($localeValues) as $index => $value) {
            // Only the default-locale entry keeps the (global) image roles; the
            // rest live in the gallery role-less and are switched via `disabled`.
            $roles = 0 === $index ? $imageRoles : [];

            $converted = parent::convertRelativeUrlToBase64(
                [['data' => $value['data']]],
                $mediaAltText,
                $position,
                $roles,
                $mediaAttribute,
                $flag,
                $disable
            );

            if (!$converted) {
                continue;
            }

            $converted['meta']['locale'] = $value['locale'];

            if (null === $default) {
                $default = $converted;
            } else {
                $this->pendingLocalizedEntries[] = $converted;
            }
        }

        return $default;
    }

    /**
     * Returns the per-locale values when the attribute value is genuinely
     * localized (>=2 locales pointing to different files); null otherwise.
     *
     * @param mixed $entry
     *
     * @return list<array{locale: string, data: string}>|null
     */
    private function extractLocalizedValues($entry): ?array
    {
        if (!is_array($entry)) {
            return null;
        }

        $values = [];

        foreach ($entry as $value) {
            if (!is_array($value) || empty($value['data']) || empty($value['locale'])) {
                continue;
            }

            $values[] = ['locale' => $value['locale'], 'data' => $value['data']];
        }

        if (count($values) < 2) {
            return null;
        }

        if (count(array_unique(array_column($values, 'data'))) < 2) {
            return null;
        }

        return $values;
    }
}

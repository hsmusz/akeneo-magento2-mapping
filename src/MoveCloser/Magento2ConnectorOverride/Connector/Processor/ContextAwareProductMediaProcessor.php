<?php

declare(strict_types=1);

namespace MoveCloser\Magento2ConnectorOverride\Connector\Processor;

use Webkul\Magento2Bundle\Connector\Processor\ProductMediaProcessor;

/**
 * Media processor override for per-locale images.
 *
 * Vanilla \Webkul\Magento2Bundle\Connector\Processor\ProductMediaProcessor collapses a
 * localizable image attribute to a single gallery entry (reads only $value[0]['data'] in
 * convertRelativeUrlToBase64), so per-language images never reach Magento.
 *
 * This override emits ONE gallery entry per (attribute, locale) with a distinct file, tagging
 * each entry via meta:
 *   - meta.locale : locale the image belongs to, or null when the attribute is not localizable
 *                   (single value = available in every store);
 *   - meta.is_base / meta.is_small / meta.is_thumbnail : role flags derived from the connector's
 *     base_image / small_image / thumbnail mapping.
 *
 * Native Magento roles (types) are intentionally left empty on PIM entries - visibility and roles
 * are driven per store view by the companion Magento module (MoveCloser_LocalizedMedia) using the
 * markers the writer posts. Non-localizable images and manually added Magento images keep the
 * default "visible everywhere" behaviour.
 *
 * @author MoveCloser
 */
class ContextAwareProductMediaProcessor extends ProductMediaProcessor
{
    /**
     * Extra per-locale entries produced for the current item, flushed by {@see self::process()}.
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
     * For a localized value (>=2 locales with different files) builds one entry per locale, tagged
     * with meta.locale and role flags; returns the first and stashes the rest. Any other value is
     * delegated to the parent and then annotated with meta.locale=null and role flags.
     *
     * @param mixed $entry
     * @param mixed $mediaAltText
     * @param int   $position
     * @param array $imageRoles
     * @param mixed $mediaAttribute
     * @param bool  $flag
     * @param bool  $disable
     */
    protected function convertRelativeUrlToBase64($entry, $mediaAltText = '', $position = 0, $imageRoles = [], $mediaAttribute = null, $flag = false, $disable = false)
    {
        $localeValues = $this->extractLocalizedValues($entry);
        // $imageRoles carries the Magento role names the parent already resolved for THIS product
        // type: getImageRoles() for models/simples, getChildImageRoles() (child_base_image/...) for
        // variants. Reading them here keeps base/small/thumbnail correct for both.
        $roles = [
            'is_base'      => in_array('image', (array) $imageRoles, true),
            'is_small'     => in_array('small_image', (array) $imageRoles, true),
            'is_thumbnail' => in_array('thumbnail', (array) $imageRoles, true),
        ];

        if (null === $localeValues) {
            // Non-localized / single value / video image: one entry, "available everywhere".
            $converted = parent::convertRelativeUrlToBase64($entry, $mediaAltText, $position, [], $mediaAttribute, $flag, $disable);

            if ($converted) {
                $converted['meta']['locale'] = null;
                $converted['meta'] += $roles;
            }

            return $converted;
        }

        $default = null;

        foreach (array_values($localeValues) as $value) {
            $converted = parent::convertRelativeUrlToBase64(
                [['data' => $value['data']]],
                $mediaAltText,
                $position,
                [],
                $mediaAttribute,
                $flag,
                $disable
            );

            if (!$converted) {
                continue;
            }

            $converted['meta']['locale'] = $value['locale'];
            $converted['meta'] += $roles;

            if (null === $default) {
                $default = $converted;
            } else {
                $this->pendingLocalizedEntries[] = $converted;
            }
        }

        return $default;
    }

    /**
     * Returns the per-locale values of a LOCALIZABLE image attribute (each normalized value carries
     * a non-empty locale), or null when the attribute is not localizable (values have no locale).
     *
     * An image belongs to every locale for which it has a value - even a single one: a gallery
     * image set only in pl_PL belongs to pl_PL (visible only there), NOT to every store. Only a
     * non-localizable attribute (no locale on its value) is treated as "available everywhere".
     *
     * @param mixed $entry
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

        return $values ?: null;
    }
}

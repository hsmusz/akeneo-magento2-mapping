<?php

declare(strict_types=1);

namespace MoveCloser\Magento2ConnectorOverride\Connector\Writer;

use Webkul\Magento2Bundle\Connector\Writer\ProductMediaWriter;

/**
 * Media writer override that makes per-language images visible per store view.
 *
 * Vanilla \Webkul\Magento2Bundle\Connector\Writer\ProductMediaWriter uploads
 * every gallery entry only to the 'all' scope
 * (\Webkul\Magento2Bundle\Connector\Writer\ProductMediaWriter::write() calls
 * postMediaCheck($item, 'all')), so one image is shared by every store view.
 *
 * Paired with
 * \MoveCloser\Magento2ConnectorOverride\Connector\Processor\ContextAwareProductMediaProcessor
 * (which emits one gallery entry per locale, tagged with meta.locale), this
 * override runs a second pass after the normal upload: for every localized entry
 * it sets the store-view-scoped `disabled` flag — enabled for the store view
 * whose locale matches the entry, disabled everywhere else. The headless front
 * reads Magento GraphQL `media_gallery` (which honours per-store `disabled`) and
 * shows the right image per language. The global image role is left untouched.
 *
 * Entries without meta.locale (ordinary, non-localized images) are ignored here,
 * so their behaviour is exactly the vanilla one.
 *
 * @author MoveCloser
 */
class ContextAwareProductMediaWriter extends ProductMediaWriter
{
    /**
     * {@inheritdoc}
     *
     * Delegates the actual upload to the parent (creates every entry at 'all'
     * scope and records the media mappings), then scopes the visibility of the
     * localized entries per store view.
     */
    public function write(array $items)
    {
        parent::write($items);

        foreach ($items as $mainItem) {
            $this->applyLocalizedVisibility($mainItem);
        }
    }

    /**
     * Applies per-store-view `disabled` for the localized entries of both the
     * product and — when present — its parent model.
     *
     * @param array<string, mixed> $mainItem
     */
    private function applyLocalizedVisibility(array $mainItem): void
    {
        $sku = $mainItem['metadata']['identifier'] ?? $mainItem['sku'] ?? null;

        if ($sku) {
            $this->scopeLocalizedEntries((string) $sku, $mainItem['media_gallery_entries'] ?? []);
        }

        if (!empty($mainItem['parent'])) {
            $parentSku = $mainItem['parent']['sku'] ?? $mainItem['parent']['metadata']['identifier'] ?? null;

            if ($parentSku) {
                $this->scopeLocalizedEntries((string) $parentSku, $mainItem['parent']['media_gallery_entries'] ?? []);
            }
        }
    }

    /**
     * For each localized gallery entry (meta.locale set) toggles `disabled` on
     * every mapped store view: enabled only where the store-view locale matches
     * the entry locale.
     *
     * @param list<array<string, mixed>> $entries
     */
    private function scopeLocalizedEntries(string $sku, array $entries): void
    {
        foreach ($entries as $entry) {
            $entryLocale = $entry['meta']['locale'] ?? null;
            $name = $entry['content']['name'] ?? null;

            if (!$entryLocale || !$name) {
                continue;
            }

            $mapping = $this->getMediaMappingByCode($name, 'media', false, null, $sku);

            if (!$mapping || !$mapping->getExternalId()) {
                continue;
            }

            $externalId = $mapping->getExternalId();

            foreach ($this->storeMappings as $storeViewCode => $storeMapping) {
                if (self::DEFAULT_STORE_VIEW_CODE === $storeViewCode || empty($storeMapping['locale'])) {
                    continue;
                }

                $disabled = $storeMapping['locale'] !== $entryLocale;

                $this->updateProductMedia($externalId, $sku, $storeViewCode, [
                    'entry' => [
                        'id'         => (int) $externalId,
                        'media_type' => 'image',
                        'label'      => $entry['label'] ?? null,
                        'position'   => $entry['position'] ?? 0,
                        'disabled'   => $disabled,
                    ],
                ]);
            }
        }
    }
}

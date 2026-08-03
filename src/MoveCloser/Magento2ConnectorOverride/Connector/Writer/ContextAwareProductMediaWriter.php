<?php

declare(strict_types=1);

namespace MoveCloser\Magento2ConnectorOverride\Connector\Writer;

use Akeneo\Tool\Component\Batch\Item\DataInvalidItem;
use Webkul\Magento2Bundle\Connector\Writer\ProductMediaWriter;

/**
 * Media writer override for per-locale images with non-destructive reconciliation.
 *
 * It fully replaces the vanilla write flow, which uploads to the global scope AND hard-deletes any
 * Magento media that is not present in the PIM mapping - including images added by hand in the
 * Magento admin. Here instead:
 *
 *  1. every gallery entry is uploaded once to the global ('all') gallery;
 *  2. a dedicated PIM table {@see self::MAP_TABLE} records content_name -> Magento value_id per
 *     (apiUrl, sku), so a re-export reuses existing images and can tell exactly which media THIS
 *     PIM created;
 *  3. media that the PIM tracked before but no longer exports (removed or replaced in Akeneo) is
 *     deleted from Magento; media the PIM never tracked (manual / non-PIM) is NEVER touched;
 *  4. per-store visibility + role markers are posted to the companion Magento module
 *     (MoveCloser_LocalizedMedia), which a GraphQL resolver uses to expose the right images and
 *     base/small/thumbnail per store view.
 *
 * @author MoveCloser
 */
class ContextAwareProductMediaWriter extends ProductMediaWriter
{
    private const MARKER_ENDPOINT = '/V1/movecloser/localized-media/markers';
    private const MAP_TABLE = 'wk_magento2_media_mapping';

    private bool $mapTableEnsured = false;

    /**
     * {@inheritdoc}
     *
     * Non-destructive, mapping-driven reconciliation. The vanilla parent::write() is intentionally
     * NOT called - it would delete manual Magento media.
     */
    public function write(array $items)
    {
        if (!$this->oauthClient) {
            $this->stepExecution->addWarning('invalid oauth client', [], new DataInvalidItem([]));

            return;
        }

        $this->ensureMapTable();

        $addNewOnly = !empty($this->getParameters()['addNewOnly']);

        foreach ($items as $mainItem) {
            if ($addNewOnly && $this->skipAsAlreadyExported($mainItem)) {
                continue;
            }

            if (!empty($mainItem['parent'])) {
                $parentSku = $mainItem['parent']['sku'] ?? $mainItem['parent']['metadata']['identifier'] ?? null;

                if ($parentSku) {
                    $this->reconcileMedia((string) $parentSku, $mainItem['parent']['media_gallery_entries'] ?? []);
                }
            }

            $sku = $mainItem['metadata']['identifier'] ?? $mainItem['sku'] ?? null;

            if ($sku) {
                $this->reconcileMedia((string) $sku, $mainItem['media_gallery_entries'] ?? []);
            }
        }
    }

    /**
     * Mirrors the vanilla writer's addNewOnly gate: skip (and count as already_exported) an item that
     * has no pending entity track, otherwise consume its track. Kept so the "add new only" job option
     * behaves as it did before this override replaced the write flow.
     *
     * @param array<string, mixed> $mainItem
     */
    private function skipAsAlreadyExported(array $mainItem): bool
    {
        $identifier = $mainItem['metadata']['identifier'] ?? null;
        $updateTrack = $identifier ? $this->connectorService->getEntityTrackByEntityAndCode('product', $identifier) : null;
        $parentUpdateTrack = null;

        if (!empty($mainItem['parent'])) {
            $parentSku = $mainItem['parent']['sku'] ?? null;
            $parentUpdateTrack = $parentSku ? $this->connectorService->getEntityTrackByEntityAndCode('product-model', $parentSku) : null;

            if (!$updateTrack && !$parentUpdateTrack) {
                $this->stepExecution->incrementSummaryInfo('already_exported');

                return true;
            }
        } elseif (!$updateTrack) {
            $this->stepExecution->incrementSummaryInfo('already_exported');

            return true;
        }

        if (!empty($updateTrack)) {
            $this->connectorService->removeTrack($updateTrack);
        }

        if (!empty($parentUpdateTrack)) {
            $this->connectorService->removeTrack($parentUpdateTrack);
        }

        return false;
    }

    /**
     * Brings the product's Magento media in line with the current PIM export, without deleting
     * anything the PIM did not put there.
     *
     * @param list<array<string, mixed>> $entries
     */
    private function reconcileMedia(string $sku, array $entries): void
    {
        $apiUrl = $this->apiUrl();
        $mapRows = $this->loadMap($apiUrl, $sku);

        if (!$entries && !$mapRows) {
            return;
        }

        if (!$this->getMagentoProductData($sku, 'all')) {
            return;
        }

        [$existingIds, $existingByName, $existingRaw] = $this->existingMedia($sku);
        $localeToStoreCodes = $this->localeToStoreCodes();

        // By-name adoption is only safe when this SKU is ALREADY tracked (the map has rows but this
        // particular name/locale drifted): then a gallery image with the PIM name is one the PIM put
        // there. With an EMPTY map (first export / not yet seeded) by-name would grab manual admin
        // images and later delete them, so we skip adoption and let the upload proceed (Magento
        // renames PIM's file on collision, leaving the manual image untouched). The reconcile command
        // is the sanctioned way to seed the map and dedupe pre-existing images.
        $mapExistsForSku = [] !== $mapRows;

        // Index the previous map. locale '' means the file is non-localizable (available everywhere).
        $nameToValueId = [];
        $mappedByLocale = [];
        $valueIdLocales = [];

        foreach ($mapRows as $row) {
            $name = (string) $row['content_name'];
            $valueId = (int) $row['value_id'];
            $locale = (string) $row['locale'];

            $nameToValueId[$name] = $valueId;
            $mappedByLocale[$locale][$name] = $valueId;
            $valueIdLocales[$valueId][$locale] = true;
        }

        $markers = [];
        $sentByLocale = [];
        $exportedLocales = [];
        $writeCount = 0;
        $updateCount = 0;

        foreach ($entries as $entry) {
            $name = $entry['content']['name'] ?? null;

            if (!$name) {
                continue;
            }

            $locale = (string) ($entry['meta']['locale'] ?? '');
            $exportedLocales[$locale] = true;
            $created = false;

            if (isset($nameToValueId[$name]) && isset($existingIds[$nameToValueId[$name]])) {
                // Known and still present: reuse, no re-upload.
                $valueId = $nameToValueId[$name];
            } elseif ($mapExistsForSku && isset($existingByName[$name])) {
                // Tracked SKU whose map row for this name/locale drifted: adopt the gallery image
                // instead of duplicating. Never reached for an untracked SKU (empty map), so a manual
                // image sharing a PIM file name is never adopted and thus never becomes deletable.
                $valueId = $existingByName[$name];
            } else {
                $valueId = $this->createMedia($sku, $entry);

                if (null === $valueId) {
                    continue;
                }

                $existingIds[$valueId] = true;
                $existingByName[$name] = $valueId;
                $created = true;
            }

            $nameToValueId[$name] = $valueId;
            $this->upsertMap($apiUrl, $sku, (string) $name, $valueId, $locale);
            $valueIdLocales[$valueId][$locale] = true;
            $sentByLocale[$locale][$name] = true;
            $this->collectMarkers($entry['meta'] ?? [], $valueId, $localeToStoreCodes, $markers);

            // Feed the batch summary so the report reflects the work done (the vanilla writer's
            // counters are bypassed together with parent::write()).
            if ($created) {
                $this->stepExecution->incrementSummaryInfo('write');
                $this->stepExecution->addSummaryInfo('write media', sprintf('Total media %d of %s', ++$writeCount, $sku));
            } else {
                $this->stepExecution->incrementSummaryInfo('update');
                $this->stepExecution->addSummaryInfo('update media', sprintf('Total media %d of %s', ++$updateCount, $sku));
            }
        }

        // Locale-scoped deletion over the export's FULL scope (the job's filter locales + '' for
        // non-localizable), not merely the locales that happened to yield an entry: a locale that
        // just lost its LAST image sends no entry, yet its stale map rows must still be cleared. A
        // locale outside this job (e.g. a narrowed export) is not in scope and stays untouched. A
        // file is deleted from Magento only once NO locale references it; untracked (manual) media
        // is not in the map and is therefore never removed.
        $scopeLocales = $this->reconcileScopeLocales($exportedLocales);
        $orphanCandidates = [];

        foreach ($scopeLocales as $locale) {
            foreach ($mappedByLocale[$locale] ?? [] as $name => $valueId) {
                if (isset($sentByLocale[$locale][$name])) {
                    continue;
                }

                $this->deleteMapRow($apiUrl, $sku, (string) $name, $locale);
                unset($valueIdLocales[$valueId][$locale]);
                $orphanCandidates[$valueId] = true;
            }
        }

        foreach (array_keys($orphanCandidates) as $valueId) {
            if (empty($valueIdLocales[$valueId]) && isset($existingIds[$valueId])) {
                // Magento won't delete a media that still holds a native role, so strip the role
                // first; otherwise the orphan lingers marker-less and is then treated as a manual
                // image (visible in every store) - leaking a dropped/replaced localized image.
                $this->releaseMediaRoles($sku, $valueId, $existingRaw[$valueId] ?? null);
                $this->removeProductMedia($valueId, $sku, 'all');
            }
        }

        $this->applyRoleFallback($markers);
        $this->postMarkers($sku, $markers, $this->storeScopeFor($scopeLocales, $localeToStoreCodes));
    }

    /**
     * Guarantees each store view that shows any image also has a base/small/thumbnail image.
     *
     * Roles come from the attribute mapped as base/small/thumbnail (e.g. Image_ecommerce_1). When a
     * locale has no value for that attribute, none of its images carries the role there; in that case
     * the FIRST image of the locale (first marker for the store, in gallery order) is promoted to it.
     * A non-localized image (store_code 'all') that already carries a role satisfies it for every
     * store, so localized fallback is skipped for that role.
     *
     * @param list<array<string, mixed>> $markers
     */
    private function applyRoleFallback(array &$markers): void
    {
        $roles = ['is_base', 'is_small', 'is_thumbnail'];

        $byStore = [];

        foreach ($markers as $index => $marker) {
            $byStore[$marker['store_code'] ?? 'all'][] = $index;
        }

        // Non-localized ("all") images first: promote the first one to any role none of them carry,
        // then treat that role as provided for every store view.
        $allProvides = array_fill_keys($roles, false);

        if (!empty($byStore['all'])) {
            foreach ($roles as $role) {
                foreach ($byStore['all'] as $index) {
                    if (!empty($markers[$index][$role])) {
                        $allProvides[$role] = true;
                        break;
                    }
                }

                if (!$allProvides[$role]) {
                    $markers[$byStore['all'][0]][$role] = true;
                    $allProvides[$role] = true;
                }
            }
        }

        foreach ($byStore as $storeCode => $indexes) {
            if ($storeCode === 'all') {
                continue;
            }

            foreach ($roles as $role) {
                if ($allProvides[$role]) {
                    continue;
                }

                $has = false;

                foreach ($indexes as $index) {
                    if (!empty($markers[$index][$role])) {
                        $has = true;
                        break;
                    }
                }

                if (!$has) {
                    $markers[$indexes[0]][$role] = true;
                }
            }
        }
    }

    /**
     * Uploads one gallery entry to the global scope and returns its new Magento value id.
     */
    private function createMedia(string $sku, array $entry): ?int
    {
        $payload = $entry;
        unset($payload['meta']);

        $response = $this->createProductMedia($sku, 'all', ['entry' => $payload]);

        if (is_array($response) && isset($response['error'])) {
            $this->warn($sku, 'LocalizedMedia: media upload failed: ' . (is_string($response['error']) ? $response['error'] : json_encode($response['error'])));

            return null;
        }

        $valueId = (int) trim((string) $response, '"');

        if ($valueId <= 0) {
            $this->warn($sku, 'LocalizedMedia: media upload returned no id (' . substr((string) $response, 0, 120) . ')');

            return null;
        }

        return $valueId;
    }

    /**
     * Strips the native base/small/thumbnail roles from a media entry so it can be deleted.
     *
     * Magento auto-assigns those roles to the first uploaded image; a role-bearing media is rejected
     * by the delete API. Our roles are driven per store view by markers, so clearing the native role
     * has no storefront effect - it only unblocks removal of an orphaned localized image.
     *
     * @param array<string, mixed>|null $rawEntry the current Magento media entry (from existingMedia)
     */
    private function releaseMediaRoles(string $sku, int $valueId, ?array $rawEntry): void
    {
        if (null === $rawEntry || empty($rawEntry['types'])) {
            return;
        }

        $entry = [
            'id'         => $valueId,
            'media_type' => $rawEntry['media_type'] ?? 'image',
            'label'      => $rawEntry['label'] ?? '',
            'position'   => (int) ($rawEntry['position'] ?? 0),
            'disabled'   => (bool) ($rawEntry['disabled'] ?? false),
            'types'      => [],
        ];

        $response = $this->updateProductMedia($valueId, $sku, 'all', ['entry' => $entry]);

        if (is_array($response) && isset($response['error'])) {
            $this->warn($sku, 'LocalizedMedia: could not clear native roles before delete (value ' . $valueId . '): ' . (is_string($response['error']) ? $response['error'] : json_encode($response['error'])));
        }
    }

    /**
     * Current Magento gallery of the product, indexed for reuse.
     *
     * @return array{0: array<int, true>, 1: array<string, int>, 2: array<int, array<string, mixed>>}
     *         [value_id => true], [file basename => value_id], [value_id => raw media entry]
     */
    private function existingMedia(string $sku): array
    {
        $existing = $this->getProductMedias($sku, 'all');
        $ids = [];
        $byName = [];
        $rawById = [];

        if (is_array($existing) && !isset($existing['error'])) {
            foreach ($existing as $media) {
                if (!isset($media['id'])) {
                    continue;
                }

                $valueId = (int) $media['id'];
                $ids[$valueId] = true;
                $rawById[$valueId] = $media;

                if (!empty($media['file'])) {
                    $base = basename((string) $media['file']);
                    $byName[$base] = $valueId;
                    // Magento appends "_N" to a filename on collision; index the clean form too so a
                    // re-upload of the same PIM file is recognised instead of duplicated.
                    $clean = preg_replace('/_\d+(\.[^.]+)$/', '$1', $base);

                    if (is_string($clean) && $clean !== $base) {
                        $byName[$clean] = $valueId;
                    }
                }
            }
        }

        return [$ids, $byName, $rawById];
    }

    /**
     * Turns an entry's meta (locale + role flags) into per-store markers.
     *
     * @param array<string, mixed>        $meta
     * @param array<string, list<string>> $localeToStoreCodes
     * @param list<array<string, mixed>>  $markers
     */
    private function collectMarkers(array $meta, int $valueId, array $localeToStoreCodes, array &$markers): void
    {
        $roles = [
            'is_base'      => !empty($meta['is_base']),
            'is_small'     => !empty($meta['is_small']),
            'is_thumbnail' => !empty($meta['is_thumbnail']),
        ];
        $locale = $meta['locale'] ?? null;

        if (null === $locale) {
            $markers[] = ['value_id' => $valueId, 'store_code' => 'all'] + $roles;

            return;
        }

        foreach ($localeToStoreCodes[$locale] ?? [] as $storeCode) {
            $markers[] = ['value_id' => $valueId, 'store_code' => $storeCode] + $roles;
        }
    }

    /**
     * locale => [store view codes] from the credential store mapping (skips the sentinel).
     *
     * @return array<string, list<string>>
     */
    private function localeToStoreCodes(): array
    {
        $map = [];

        foreach ($this->getStoreMapping() as $storeViewCode => $storeMapping) {
            if ($storeViewCode === self::DEFAULT_STORE_VIEW_CODE || empty($storeMapping['locale'])) {
                continue;
            }

            $map[$storeMapping['locale']][] = $storeViewCode;
        }

        return $map;
    }

    /**
     * Locales this export reconciles: the job's filter locales plus '' (non-localizable / all-store).
     * Falls back to the locales present in the entries when job parameters are unavailable, so the
     * behaviour degrades to the previous (entry-scoped) reconciliation rather than over-deleting.
     *
     * @param array<string, true> $exportedLocales
     * @return list<string>
     */
    private function reconcileScopeLocales(array $exportedLocales): array
    {
        $scope = $exportedLocales;

        try {
            foreach ($this->getFilterLocales($this->stepExecution) as $locale) {
                $scope[(string) $locale] = true;
            }
        } catch (\Throwable $e) {
            // no job parameters (e.g. unit context): keep the entry-scoped fallback
        }

        $scope[''] = true; // non-localizable images live in the "all stores" scope

        return array_keys($scope);
    }

    /**
     * Magento store view codes covered by the export, derived from the scope locales. 'all' (store 0)
     * is always included so a dropped non-localizable image clears its marker.
     *
     * @param list<string>                $scopeLocales
     * @param array<string, list<string>> $localeToStoreCodes
     * @return list<string>
     */
    private function storeScopeFor(array $scopeLocales, array $localeToStoreCodes): array
    {
        $codes = ['all' => true];

        foreach ($scopeLocales as $locale) {
            foreach ($localeToStoreCodes[$locale] ?? [] as $storeCode) {
                $codes[$storeCode] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * @param list<array<string, mixed>> $markers
     * @param list<string>               $storeScope store view codes this export reconciles
     */
    private function postMarkers(string $sku, array $markers, array $storeScope = []): void
    {
        $url = $this->markerEndpointUrl();

        if (null === $url) {
            return;
        }

        try {
            $this->oauthClient->fetch(
                $url,
                json_encode([
                    'sku' => $sku,
                    'markersJson' => json_encode(array_values($markers)),
                    'storeScopeJson' => json_encode(array_values($storeScope)),
                ]),
                'POST',
                $this->jsonHeaders
            );
        } catch (\Exception $e) {
            $this->warn($sku, 'LocalizedMedia: markers push failed: ' . $e->getMessage());
        }
    }

    private function markerEndpointUrl(): ?string
    {
        $productUrl = $this->oauthClient->getApiUrlByEndpoint(self::AKENEO_ENTITY_NAME, 'all');
        $pos = strpos((string) $productUrl, '/V1/');

        if (false === $pos) {
            return null;
        }

        return substr($productUrl, 0, $pos) . self::MARKER_ENDPOINT;
    }

    private function warn(string $sku, string $message): void
    {
        $this->stepExecution->addWarning($message, [], new DataInvalidItem(['sku' => $sku]));
    }

    // --- wk_magento2_media_mapping (PIM-side media map) ---------------------------------------

    /**
     * The connector's per-host mapping key. Mirrors the (private) DataMappingTrait::getApiUrl():
     * the Magento hostName with https downgraded to http.
     */
    private function apiUrl(): string
    {
        return str_replace('https://', 'http://', $this->getHostName());
    }

    private function ensureMapTable(): void
    {
        if ($this->mapTableEnsured) {
            return;
        }

        $this->em->getConnection()->executeStatement(
            'CREATE TABLE IF NOT EXISTS ' . self::MAP_TABLE . ' (
                id INT AUTO_INCREMENT PRIMARY KEY,
                api_url VARCHAR(191) NOT NULL,
                sku VARCHAR(191) NOT NULL,
                content_name VARCHAR(191) NOT NULL,
                value_id INT NOT NULL,
                locale VARCHAR(15) NOT NULL DEFAULT \'\',
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uniq_media_map (api_url, sku, content_name, locale),
                KEY idx_media_map_sku (api_url, sku)
            ) DEFAULT CHARSET=utf8mb4'
        );

        $this->mapTableEnsured = true;
    }

    /**
     * @return list<array{content_name: string, value_id: int, locale: string}>
     */
    private function loadMap(string $apiUrl, string $sku): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT content_name, value_id, locale FROM ' . self::MAP_TABLE . ' WHERE api_url = ? AND sku = ?',
            [$apiUrl, $sku]
        );
    }

    private function upsertMap(string $apiUrl, string $sku, string $name, int $valueId, string $locale): void
    {
        $this->em->getConnection()->executeStatement(
            'INSERT INTO ' . self::MAP_TABLE . ' (api_url, sku, content_name, value_id, locale, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE value_id = VALUES(value_id), updated_at = NOW()',
            [$apiUrl, $sku, $name, $valueId, $locale]
        );
    }

    private function deleteMapRow(string $apiUrl, string $sku, string $name, string $locale): void
    {
        $this->em->getConnection()->executeStatement(
            'DELETE FROM ' . self::MAP_TABLE . ' WHERE api_url = ? AND sku = ? AND content_name = ? AND locale = ?',
            [$apiUrl, $sku, $name, $locale]
        );
    }
}

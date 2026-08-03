<?php

declare(strict_types=1);

namespace MoveCloser\Magento2ConnectorOverride\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds the PIM-side media map (wk_magento2_media_mapping) from live Magento.
 *
 * The per-locale media export (ContextAwareProductMediaWriter) reuses existing gallery images and
 * deletes only images it previously tracked, using wk_magento2_media_mapping as the source of
 * truth. When the connector is first switched to that writer - or the map is lost - this command
 * seeds it so the next export reuses existing files instead of duplicating them, and so that
 * deletion detection works from the start.
 *
 * For every product it takes the media the PIM WOULD export (the file values of the mapped image
 * attributes, across all locales) and matches them by file name against the product's live Magento
 * gallery. Only matches are written to the map; any Magento image that does not correspond to a PIM
 * value (i.e. added by hand in the admin) is left OUT of the map and therefore protected - the
 * export never deletes what it does not track.
 *
 * Dry-run by default; pass --apply to persist.
 *
 * @author MoveCloser
 */
#[AsCommand(
    name: 'magento2:reconcile-media-mappings',
    description: 'Rebuild wk_magento2_media_mapping by matching PIM image values to live Magento gallery (protects manual images).'
)]
class ReconcileMediaMappingCommand extends Command
{
    private const MAP_TABLE = 'wk_magento2_media_mapping';

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('credential-id', null, InputOption::VALUE_REQUIRED, 'Row id from wk_magento2_credentials_mapping. Defaults to the active default credential.')
            ->addOption('api-base', null, InputOption::VALUE_REQUIRED, 'Override the Magento base URL (defaults to the credential hostName).')
            ->addOption('sku', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit to these SKUs (repeatable). Default: every product/model with mapped image values.')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Persist the rebuilt map. Without it the command only reports (dry-run).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');

        try {
            $credential = $this->resolveCredential($input->getOption('credential-id'));
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $host = rtrim((string) $credential['hostName'], '/');
        $apiBase = rtrim((string) ($input->getOption('api-base') ?: $host), '/');
        $apiUrlKey = str_replace('https://', 'http://', $host);
        $token = (string) $credential['authToken'];

        $imageAttributes = $this->imageAttributes();

        if (!$imageAttributes) {
            $io->error('No image attributes are mapped (magento2_other_mapping.otherMappings.images is empty).');

            return Command::FAILURE;
        }

        $io->section(sprintf('Reconciling media map against %s (%s)', $apiBase, $apply ? 'APPLY' : 'dry-run'));

        $this->ensureMapTable();

        $skuFilter = (array) $input->getOption('sku');
        $expectedBySku = $this->expectedMediaBySku($imageAttributes, $skuFilter);

        $totalMapped = 0;
        $totalManual = 0;
        $totalMissing = 0;
        $rows = [];
        $fatalErrors = [];

        foreach ($expectedBySku as $sku => $expectedNames) {
            try {
                $media = $this->httpGetJson($apiBase . '/rest/all/V1/products/' . rawurlencode((string) $sku) . '/media', $token);
            } catch (\RuntimeException $e) {
                if ($e->getCode() === 404) {
                    // Product not in Magento yet / no media - nothing to seed.
                    continue;
                }

                // Transport error or 5xx: fetching this product's live gallery failed, so we cannot
                // safely rebuild its map. Record it and abort before any destructive change below.
                $fatalErrors[$sku] = $e->getMessage();
                $io->writeln(sprintf('  <error>%-40s FETCH FAILED: %s</error>', $sku, $e->getMessage()));
                continue;
            }

            $seen = [];
            $consumed = [];

            // Pass 1: exact filename matches are authoritative and consume the Magento image, so a
            // later "_N" strip can never re-bind that same PIM name to a different (manual) file.
            foreach ($media as $idx => $item) {
                if (!isset($item['id'], $item['file'])) {
                    continue;
                }

                $name = basename((string) $item['file']);

                if (isset($expectedNames[$name])) {
                    foreach (array_keys($expectedNames[$name]) as $locale) {
                        $rows[] = [$apiUrlKey, (string) $sku, $name, (int) $item['id'], (string) $locale];
                    }

                    $seen[$name] = true;
                    $consumed[$idx] = true;
                }
            }

            // Pass 2: Magento appends "_N" on filename collision; adopt such a renamed PIM file only
            // when its clean name is NOT already satisfied by an exact match - otherwise a manually
            // uploaded "foo_1.jpg" (with PIM's "foo.jpg" present) would be wrongly bound and become
            // deletable. First writer wins per clean name, so a duplicate "_2" stays manual/protected.
            foreach ($media as $idx => $item) {
                if (isset($consumed[$idx]) || !isset($item['id'], $item['file'])) {
                    continue;
                }

                $name = basename((string) $item['file']);
                $clean = preg_replace('/_\d+(\.[^.]+)$/', '$1', $name);

                if (is_string($clean) && $clean !== $name && isset($expectedNames[$clean]) && !isset($seen[$clean])) {
                    foreach (array_keys($expectedNames[$clean]) as $locale) {
                        $rows[] = [$apiUrlKey, (string) $sku, $clean, (int) $item['id'], (string) $locale];
                    }

                    $seen[$clean] = true;
                    $consumed[$idx] = true;
                }
            }

            $matched = count($seen);
            $manual = 0;

            foreach ($media as $idx => $item) {
                if (isset($item['id'], $item['file']) && !isset($consumed[$idx])) {
                    ++$manual;
                }
            }

            $missing = count(array_diff_key($expectedNames, $seen));
            $totalMapped += $matched;
            $totalManual += $manual;
            $totalMissing += $missing;

            $io->writeln(sprintf('  %-40s mapped=%d  manual(kept)=%d  not-in-magento=%d', $sku, $matched, $manual, $missing));
        }

        if ($fatalErrors) {
            $io->error(sprintf(
                '%d product(s) could not be fetched (transport/5xx): %s. Aborting without changing the map so no tracking is lost.',
                count($fatalErrors),
                implode(', ', array_keys($fatalErrors))
            ));

            return Command::FAILURE;
        }

        if ($apply) {
            // Wipe only now that every product's gallery was fetched successfully, so a mid-run
            // transport error can never strand a product with its map already deleted. Scope the wipe
            // to the reconciled SKUs; a filtered run must not erase the map of other products.
            if ($skuFilter) {
                $this->connection->executeStatement(
                    'DELETE FROM ' . self::MAP_TABLE . ' WHERE api_url = :url AND sku IN (:skus)',
                    ['url' => $apiUrlKey, 'skus' => array_values($skuFilter)],
                    ['skus' => Connection::PARAM_STR_ARRAY]
                );
            } else {
                $this->connection->executeStatement(
                    'DELETE FROM ' . self::MAP_TABLE . ' WHERE api_url = :url',
                    ['url' => $apiUrlKey]
                );
            }

            foreach ($rows as [$url, $sku, $name, $valueId, $locale]) {
                $this->connection->executeStatement(
                    'INSERT INTO ' . self::MAP_TABLE . ' (api_url, sku, content_name, value_id, locale, updated_at)
                     VALUES (?, ?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE value_id = VALUES(value_id), updated_at = NOW()',
                    [$url, $sku, $name, $valueId, $locale]
                );
            }
        }

        $io->newLine();
        $io->success(sprintf(
            '%s: %d media mapped, %d manual images protected, %d PIM values not yet in Magento.',
            $apply ? 'Applied' : 'Dry-run',
            $totalMapped,
            $totalManual,
            $totalMissing
        ));

        return Command::SUCCESS;
    }

    /**
     * Image attribute codes from the connector's otherMappings.images.
     *
     * @return array<string, true>
     */
    private function imageAttributes(): array
    {
        $value = $this->connection->fetchOne(
            "SELECT value FROM oro_config_value WHERE section = 'magento2_other_mapping' AND name = 'otherMappings'"
        );

        $decoded = is_string($value) ? json_decode($value, true) : null;
        $images = is_array($decoded) && isset($decoded['images']) && is_array($decoded['images']) ? $decoded['images'] : [];

        return array_fill_keys($images, true);
    }

    /**
     * The media the PIM would export per SKU, with the locale(s) each file belongs to. A localizable
     * value carries its locale; a non-localizable value ("<all_locales>") maps to '' (all stores).
     *
     * @param array<string, true> $imageAttributes
     * @param list<string>        $skuFilter
     * @return array<string, array<string, array<string, true>>> sku => [file name => [locale => true]]
     */
    private function expectedMediaBySku(array $imageAttributes, array $skuFilter): array
    {
        $result = [];

        foreach (['pim_catalog_product' => 'identifier', 'pim_catalog_product_model' => 'code'] as $table => $idColumn) {
            $sql = 'SELECT ' . $idColumn . ' AS sku, raw_values FROM ' . $table;
            $rows = $this->connection->iterateAssociative($sql);

            foreach ($rows as $row) {
                $sku = (string) $row['sku'];

                if ($skuFilter && !in_array($sku, $skuFilter, true)) {
                    continue;
                }

                $values = json_decode((string) $row['raw_values'], true);

                if (!is_array($values)) {
                    continue;
                }

                foreach ($values as $attribute => $channels) {
                    if (!isset($imageAttributes[$attribute]) || !is_array($channels)) {
                        continue;
                    }

                    foreach ($channels as $locales) {
                        foreach ((array) $locales as $localeCode => $file) {
                            if (!is_string($file) || $file === '') {
                                continue;
                            }

                            $name = substr(basename($file), -85);
                            $locale = $localeCode === '<all_locales>' ? '' : (string) $localeCode;
                            $result[$sku][$name][$locale] = true;
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveCredential(?string $credentialId): array
    {
        if ($credentialId !== null) {
            $row = $this->connection->fetchAssociative(
                'SELECT id, hostName, authToken FROM wk_magento2_credentials_mapping WHERE id = :id',
                ['id' => (int) $credentialId]
            );

            if (!$row) {
                throw new \RuntimeException(sprintf('No credential with id %s.', $credentialId));
            }

            return $row;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, hostName, authToken, defaultSet FROM wk_magento2_credentials_mapping WHERE active = 1'
        );

        if (!$rows) {
            throw new \RuntimeException('No active credential configured.');
        }

        $default = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['defaultSet'] === 1));
        $picked = $default ?: $rows;

        if (count($picked) > 1) {
            $list = implode(', ', array_map(static fn (array $r): string => sprintf('#%s %s', $r['id'], $r['hostName']), $picked));

            throw new \RuntimeException(sprintf('Multiple credentials match; pass --credential-id. Candidates: %s', $list));
        }

        return $picked[0];
    }

    private function ensureMapTable(): void
    {
        $this->connection->executeStatement(
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
    }

    /**
     * @return array<string, mixed>
     */
    private function httpGetJson(string $url, string $token): array
    {
        $handle = curl_init($url);
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        $caBundle = getenv('CURL_CA_BUNDLE');
        if (is_string($caBundle) && $caBundle !== '') {
            $curlOptions[CURLOPT_CAINFO] = $caBundle;
        }

        curl_setopt_array($handle, $curlOptions);

        $body = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            // code 0 = transport failure (timeout/DNS/SSL): must not be mistaken for a 404.
            throw new \RuntimeException($error !== '' ? $error : 'transport error', 0);
        }

        if ($status < 200 || $status >= 300) {
            // Carry the HTTP status as the exception code so the caller can tell 404 (no media)
            // apart from 5xx/other errors that must NOT trigger a destructive rebuild.
            throw new \RuntimeException(sprintf('HTTP %d: %s', $status, substr((string) $body, 0, 200)), $status);
        }

        $decoded = json_decode((string) $body, true);

        return is_array($decoded) ? $decoded : [];
    }
}

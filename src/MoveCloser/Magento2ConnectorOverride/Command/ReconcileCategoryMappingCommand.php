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
 * Reconciles category mappings of the Webkul Magento2 connector by TREE PATH
 * under the correct brand root — not by name.
 *
 * With several brand PIMs exporting into one multi-brand Magento, category
 * names collide across brands ("Linie" exists under Dr Irena Eris AND Lirene)
 * and even within a brand under different parents ("Potrzeby" under both
 * "Pielęgnacja Twarzy" and "Pielęgnacja Ciała"). Webkul's root-blind matching
 * bound this PIM's categories to the wrong brand's ids, so products land in the
 * wrong brand. Name-based repair is therefore unsafe.
 *
 * This command resolves each Akeneo category to the Magento category whose full
 * label-path from the brand root matches, then repairs wrong mappings (e.g. an
 * id pointing into the Lirene tree → the matching id in this brand's tree) and
 * creates missing ones. It writes only the PIM mapping table and never calls
 * Magento for writes.
 *
 * Run separately in each PIM (each PIM = one brand root).
 *
 * @author MoveCloser
 */
#[AsCommand(
    name: 'magento2:reconcile-category-mappings',
    description: 'Repair category id mappings by matching the tree path under the correct brand root (fixes cross-brand category contamination).'
)]
class ReconcileCategoryMappingCommand extends Command
{
    private const ENTITY_TYPE = 'category';

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('credential-id', null, InputOption::VALUE_REQUIRED, 'Row id from wk_magento2_credentials_mapping to target. Defaults to the active default credential.')
            ->addOption('root-id', null, InputOption::VALUE_REQUIRED, 'Magento category id of this brand root. Auto-detected from the Akeneo root label when omitted.')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Akeneo locale whose labels match the Magento category names. Defaults to the credential default locale, then pl_PL.')
            ->addOption('api-base', null, InputOption::VALUE_REQUIRED, 'Override the Magento base URL used for API calls. Defaults to the credential hostName.')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Persist the corrected/created mappings. Without it the command only reports (dry-run).');
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

        if (($credential['authMethod'] ?? null) !== 'token') {
            $io->error(sprintf('Unsupported auth method "%s". Token auth only.', (string) $credential['authMethod']));

            return Command::FAILURE;
        }

        $host = rtrim((string) $credential['hostName'], '/');
        $apiUrlKey = str_replace('https://', 'http://', $host);
        $apiBase = rtrim((string) ($input->getOption('api-base') ?: $host), '/');
        if (!preg_match('#^https?://#', $apiBase)) {
            $apiBase = 'https://' . $apiBase;
        }
        $token = (string) $credential['authToken'];
        $locale = (string) ($input->getOption('locale') ?: $this->defaultLocale($credential) ?: 'pl_PL');

        $io->title('Magento2 category mapping reconciliation (path/brand-aware)');

        [$akeneoRootCode, $akeneoPaths, $akeneoRootLabel] = $this->akeneoCategoryPaths($locale);
        if (!$akeneoRootCode) {
            $io->error('No Akeneo category tree found.');

            return Command::FAILURE;
        }

        try {
            $magentoCats = $this->fetchMagentoCategories($apiBase, $token);
        } catch (\RuntimeException $e) {
            $io->error('Magento categories API call failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        try {
            $rootId = $this->resolveBrandRoot($input->getOption('root-id'), $akeneoRootLabel, $magentoCats);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        [$magentoByKey, $magentoAmbiguous] = $this->magentoPathIndex($magentoCats, (int) $rootId);
        $brandOf = fn ($id): string => $this->brandRootName($magentoCats, (string) $id);

        $io->definitionList(
            ['Credential id' => (string) $credential['id']],
            ['Host / apiUrl key' => $host . '  →  ' . $apiUrlKey],
            ['Locale for labels' => $locale],
            ['Brand root (Magento id)' => $rootId . ' = ' . ($magentoCats[(string) $rootId]['name'] ?? '?')],
            ['Mode' => $apply ? 'APPLY (will write)' : 'dry-run (read-only)']
        );

        $existing = $this->existingMappings($apiUrlKey);

        $ok = 0;
        $updates = [];      // [id, code, old, new, oldBrand]
        $inserts = [];      // [code, new]
        $ambiguous = [];    // [code, reason]
        $unresolved = [];   // [code, pathLabel]

        foreach ($akeneoPaths as $code => $info) {
            $key = $info['key'];

            if (isset($magentoAmbiguous[$key])) {
                $ambiguous[] = [$code, 'multiple Magento categories share path "' . $info['label'] . '"'];
                continue;
            }

            $target = $magentoByKey[$key] ?? null;
            if ($target === null) {
                $unresolved[] = [$code, $info['label']];
                continue;
            }

            $row = $existing[$code] ?? null;

            if ($row === null) {
                $inserts[] = ['code' => $code, 'new' => $target];
            } elseif ((string) $row['externalId'] === (string) $target) {
                $ok++;
            } else {
                $updates[] = ['id' => (int) $row['id'], 'code' => $code, 'old' => (string) $row['externalId'], 'new' => $target, 'oldBrand' => $brandOf($row['externalId'])];
            }
        }

        $this->report($io, count($akeneoPaths), $ok, $updates, $inserts, $ambiguous, $unresolved, $brandOf, (string) $rootId);

        $changes = count($updates) + count($inserts);
        if ($changes === 0) {
            $io->success('Every Akeneo category is correctly mapped under the brand root. Nothing to repair.');

            return Command::SUCCESS;
        }

        if (!$apply) {
            $io->note(sprintf('Dry-run: %d mapping(s) would be repaired and %d created. Re-run with --apply.', count($updates), count($inserts)));

            return Command::SUCCESS;
        }

        [$u, $c] = $this->applyChanges($updates, $inserts, $apiUrlKey);
        $io->success(sprintf('Repaired %d and created %d category mapping(s).', $u, $c));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    private function resolveCredential(?string $credentialId): array
    {
        if ($credentialId !== null) {
            $row = $this->connection->fetchAssociative('SELECT id, hostName, authToken, authMethod, resources FROM wk_magento2_credentials_mapping WHERE id = :id', ['id' => (int) $credentialId]);
            if (!$row) {
                throw new \RuntimeException(sprintf('No credential with id %s.', $credentialId));
            }

            return $row;
        }

        $rows = $this->connection->fetchAllAssociative('SELECT id, hostName, authToken, authMethod, resources, active, defaultSet FROM wk_magento2_credentials_mapping WHERE active = 1');
        if (!$rows) {
            throw new \RuntimeException('No active credential configured.');
        }

        $default = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['defaultSet'] === 1));
        $picked = $default ?: $rows;
        if (count($picked) > 1) {
            $list = implode(', ', array_map(static fn (array $r): string => sprintf('#%s %s', $r['id'], $r['hostName']), $picked));
            throw new \RuntimeException('Multiple credentials match; pass --credential-id. Candidates: ' . $list);
        }

        return $picked[0];
    }

    /**
     * @param array<string, mixed> $credential
     */
    private function defaultLocale(array $credential): ?string
    {
        $resources = json_decode((string) ($credential['resources'] ?? ''), true);
        if (!is_array($resources)) {
            return null;
        }

        // The connector names Magento categories with the ADMIN store-view locale
        // (storeMapping.allStoreView.locale), which the BaseWriter uses as its
        // defaultLocale — this is NOT necessarily the credential's defaultLocale
        // (e.g. Pharmaceris: defaultLocale=uk_UA but admin names are pl_PL). Match
        // against the admin locale so --locale is rarely needed.
        $adminLocale = $resources['storeMapping']['allStoreView']['locale'] ?? null;
        if (!empty($adminLocale)) {
            return (string) $adminLocale;
        }

        return !empty($resources['defaultLocale']) ? (string) $resources['defaultLocale'] : null;
    }

    /**
     * Builds, for every Akeneo category, its normalized label-path from the root
     * (root excluded; the root itself maps to an empty-string key).
     *
     * @return array{0: ?string, 1: array<string, array{key: string, label: string}>, 2: ?string}
     *         [rootCode, code => {key, label}, rootLabel]
     */
    private function akeneoCategoryPaths(string $locale): array
    {
        $cats = $this->connection->fetchAllAssociative('SELECT id, code, parent_id FROM pim_catalog_category');
        if (!$cats) {
            return [null, [], null];
        }

        $byId = [];
        $rootCode = $rootLabel = null;
        $rootId = null;
        foreach ($cats as $c) {
            $byId[(int) $c['id']] = ['code' => (string) $c['code'], 'parent' => $c['parent_id'] !== null ? (int) $c['parent_id'] : null];
            if ($c['parent_id'] === null) {
                $rootCode = (string) $c['code'];
                $rootId = (int) $c['id'];
            }
        }

        $labels = [];
        foreach ($this->connection->fetchAllAssociative('SELECT foreign_key, locale, label FROM pim_catalog_category_translation') as $t) {
            $labels[(int) $t['foreign_key']][(string) $t['locale']] = (string) $t['label'];
        }
        $labelOf = function (int $id) use ($labels, $byId, $locale): string {
            $loc = $labels[$id] ?? [];

            return $loc[$locale] ?? (reset($loc) ?: $byId[$id]['code']);
        };
        if ($rootId !== null) {
            $rootLabel = $labelOf($rootId);
        }

        $paths = [];
        foreach ($byId as $id => $node) {
            $chain = [];
            $cur = $id;
            $guard = 0;
            while ($cur !== null && isset($byId[$cur]) && $byId[$cur]['parent'] !== null && $guard++ < 50) {
                $chain[] = $labelOf($cur);
                $cur = $byId[$cur]['parent'];
            }
            $chain = array_reverse($chain);
            $paths[$node['code']] = [
                'key'   => implode('/', array_map([$this, 'norm'], $chain)),
                'label' => $rootLabel . ' / ' . implode(' / ', $chain),
            ];
        }

        return [$rootCode, $paths, $rootLabel];
    }

    /**
     * @return array<string, array{id: string, name: string, parent_id: ?string, path: string, level: int}>
     *
     * @throws \RuntimeException
     */
    private function fetchMagentoCategories(string $apiBase, string $token): array
    {
        $url = $apiBase . '/rest/all/V1/categories/list?searchCriteria[pageSize]=5000&fields=items[id,name,parent_id,path,level]';
        $data = $this->httpGetJson($url, $token);
        $items = $data['items'] ?? [];

        $byId = [];
        foreach ($items as $i) {
            $byId[(string) $i['id']] = [
                'id'        => (string) $i['id'],
                'name'      => (string) ($i['name'] ?? ''),
                'parent_id' => isset($i['parent_id']) ? (string) $i['parent_id'] : null,
                'path'      => (string) ($i['path'] ?? ''),
                'level'     => (int) ($i['level'] ?? 0),
            ];
        }

        return $byId;
    }

    /**
     * @param array<string, array{id: string, name: string, path: string, level: int}> $magentoCats
     *
     * @throws \RuntimeException when the brand root cannot be resolved unambiguously
     */
    private function resolveBrandRoot(?string $rootIdOption, ?string $akeneoRootLabel, array $magentoCats): string
    {
        if ($rootIdOption !== null) {
            if (!isset($magentoCats[$rootIdOption])) {
                throw new \RuntimeException(sprintf('Magento category id %s not found.', $rootIdOption));
            }

            return $rootIdOption;
        }

        $target = $this->norm((string) $akeneoRootLabel);
        $candidates = array_filter($magentoCats, static fn (array $c): bool => $c['level'] === 1);
        $matches = array_filter($candidates, fn (array $c): bool => $this->norm($c['name']) === $target);

        if (!$matches) {
            throw new \RuntimeException(sprintf('No Magento store root named "%s". Pass --root-id.', $akeneoRootLabel));
        }

        // pick the real root (largest subtree) among same-named duplicates
        $size = [];
        foreach ($magentoCats as $c) {
            $ids = explode('/', $c['path']);
            if (isset($ids[1])) {
                $size[$ids[1]] = ($size[$ids[1]] ?? 0) + 1;
            }
        }
        uasort($matches, static fn (array $a, array $b): int => ($size[$b['id']] ?? 0) <=> ($size[$a['id']] ?? 0));
        $best = array_key_first($matches);

        return (string) $magentoCats[$best]['id'];
    }

    /**
     * @param array<string, array{id: string, name: string, path: string}> $magentoCats
     *
     * @return array{0: array<string, string>, 1: array<string, true>} [key => id, ambiguousKeys]
     */
    private function magentoPathIndex(array $magentoCats, int $rootId): array
    {
        $byKey = [];
        $dupes = [];

        foreach ($magentoCats as $c) {
            $ids = explode('/', $c['path']);
            $pos = array_search((string) $rootId, $ids, true);
            if ($pos === false) {
                continue;
            }

            $belowIds = array_slice($ids, $pos + 1);
            $names = [];
            foreach ($belowIds as $id) {
                $names[] = $this->norm($magentoCats[$id]['name'] ?? '');
            }
            $key = implode('/', $names);

            if (isset($byKey[$key]) && $byKey[$key] !== (string) $c['id']) {
                $dupes[$key] = true;
            } else {
                $byKey[$key] = (string) $c['id'];
            }
        }

        return [$byKey, $dupes];
    }

    /**
     * @param array<string, array{id: string, name: string, path: string}> $magentoCats
     */
    private function brandRootName(array $magentoCats, string $id): string
    {
        $c = $magentoCats[$id] ?? null;
        if (!$c) {
            return '(unknown id ' . $id . ')';
        }
        $ids = explode('/', $c['path']);

        return $magentoCats[$ids[1] ?? '']['name'] ?? '?';
    }

    /**
     * @return array<string, array{id: int|string, externalId: string}>
     */
    private function existingMappings(string $apiUrlKey): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, code, externalId FROM wk_magento2_data_mapping WHERE entityType = :t AND apiUrl = :u',
            ['t' => self::ENTITY_TYPE, 'u' => $apiUrlKey]
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['code']] ??= $r;
        }

        return $map;
    }

    /**
     * @param list<array{id: int, code: string, old: string, new: string}> $updates
     * @param list<array{code: string, new: string}>                        $inserts
     *
     * @return array{0: int, 1: int}
     */
    private function applyChanges(array $updates, array $inserts, string $apiUrlKey): array
    {
        $u = $c = 0;
        $this->connection->beginTransaction();
        try {
            foreach ($updates as $up) {
                $u += (int) $this->connection->executeStatement(
                    'UPDATE wk_magento2_data_mapping SET externalId = :new WHERE id = :id AND externalId = :old',
                    ['new' => $up['new'], 'id' => $up['id'], 'old' => $up['old']]
                );
            }
            foreach ($inserts as $in) {
                $c += (int) $this->connection->executeStatement(
                    'INSERT INTO wk_magento2_data_mapping (entityType, code, magentoCode, externalId, relatedId, jobInstanceId, storeViewCode, apiUrl, extras)
                     VALUES (:t, :code, :code, :ext, NULL, 0, NULL, :url, NULL)',
                    ['t' => self::ENTITY_TYPE, 'code' => $in['code'], 'ext' => $in['new'], 'url' => $apiUrlKey]
                );
            }
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }

        return [$u, $c];
    }

    /**
     * @throws \RuntimeException
     *
     * @return array<string, mixed>
     */
    private function httpGetJson(string $url, string $token): array
    {
        $h = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        $ca = getenv('CURL_CA_BUNDLE');
        if (is_string($ca) && $ca !== '') {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($h, $opts);
        $body = curl_exec($h);
        $status = curl_getinfo($h, CURLINFO_HTTP_CODE);
        $err = curl_error($h);
        curl_close($h);

        if ($body === false) {
            throw new \RuntimeException($err !== '' ? $err : 'transport error');
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('HTTP %d: %s', $status, substr((string) $body, 0, 300)));
        }
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('unexpected JSON response');
        }

        return $decoded;
    }

    private function norm(string $s): string
    {
        $s = str_replace(['ł', 'Ł'], ['l', 'L'], $s);
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false) {
            $s = $t;
        }
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s;

        return trim($s);
    }

    /**
     * @param list<array{id: int, code: string, old: string, new: string, oldBrand: string}> $updates
     * @param list<array{code: string, new: string}>                                          $inserts
     * @param list<array{0: string, 1: string}>                                               $ambiguous
     * @param list<array{0: string, 1: string}>                                               $unresolved
     */
    private function report(SymfonyStyle $io, int $scanned, int $ok, array $updates, array $inserts, array $ambiguous, array $unresolved, callable $brandOf, string $rootId): void
    {
        $io->section('Summary');
        $io->table(['Status', 'Count'], [
            ['Akeneo categories scanned', (string) $scanned],
            ['already correct', (string) $ok],
            ['WRONG brand/id -> will be repaired', (string) count($updates)],
            ['missing -> will be created', (string) count($inserts)],
            ['ambiguous path (skipped)', (string) count($ambiguous)],
            ['no path match in Magento (skipped)', (string) count($unresolved)],
        ]);

        if ($updates) {
            $io->section('Repair (wrong id/brand -> correct id under this brand root)');
            $io->table(
                ['code', 'old id', 'old brand', 'new id'],
                array_map(static fn (array $u): array => [$u['code'], $u['old'], $u['oldBrand'], $u['new']], array_slice($updates, 0, 80))
            );
            if (count($updates) > 80) {
                $io->writeln(sprintf('  … and %d more', count($updates) - 80));
            }
        }
        if ($inserts) {
            $io->section('Create');
            $io->table(['code', 'new id'], array_map(static fn (array $i): array => [$i['code'], $i['new']], array_slice($inserts, 0, 80)));
            if (count($inserts) > 80) {
                $io->writeln(sprintf('  … and %d more', count($inserts) - 80));
            }
        }
        if ($unresolved) {
            $io->section('No path match (review — category may not exist in this brand tree yet)');
            $io->table(['code', 'akeneo path'], array_map(static fn (array $r): array => [$r[0], $r[1]], array_slice($unresolved, 0, 60)));
        }
        if ($ambiguous) {
            $io->section('Ambiguous (skipped)');
            $io->table(['code', 'reason'], $ambiguous);
        }
    }
}

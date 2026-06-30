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
 * Reconciles attribute-option mappings of the Webkul Magento2 connector.
 *
 * The connector stores, per Magento host, a link between an Akeneo option
 * (`optionCode(attributeCode)`) and the numeric Magento option id in
 * `wk_magento2_data_mapping`. When that link is missing or points at a stale id,
 * the connector recreates the option via POST and Magento rejects it with
 * "Admin store attribute option label "%1" is already exists.".
 *
 * For every Akeneo option of the targeted attributes this command:
 *   - leaves a mapping whose id still exists in Magento untouched (export = PUT);
 *   - repairs a stale mapping by slug-matching it to the live Magento option;
 *   - creates a missing mapping when the option already exists in Magento;
 *   - reports options that are genuinely absent in Magento (export will create
 *     them, which is legitimate and not an error).
 *
 * Slug matching is locale-insensitive (lower-cased, diacritics transliterated),
 * which is why the Magento admin labels (option codes) line up with the Akeneo
 * option codes regardless of accents or letter case.
 *
 * @author MoveCloser
 */
#[AsCommand(
    name: 'magento2:reconcile-option-mappings',
    description: 'Repair and complete Webkul Magento2 attribute-option id mappings by slug-matching against live Magento options.'
)]
class ReconcileAttributeOptionMappingCommand extends Command
{
    private const ENTITY_TYPE = 'option';

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('credential-id', null, InputOption::VALUE_REQUIRED, 'Row id from wk_magento2_credentials_mapping to target. Defaults to the active default credential.')
            ->addOption('attribute', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Limit to these Akeneo attribute codes (repeatable). Default: every attribute that has option mappings for the host.')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Magento store code used in the REST path (admin labels live under "all").', 'all')
            ->addOption('api-base', null, InputOption::VALUE_REQUIRED, 'Override the Magento base URL used for API calls (e.g. https://shop.example.com). Defaults to the credential hostName.')
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
            $io->error(sprintf('Unsupported auth method "%s". This command supports integration token auth only.', (string) $credential['authMethod']));

            return Command::FAILURE;
        }

        $host = rtrim((string) $credential['hostName'], '/');
        $apiUrlKey = str_replace('https://', 'http://', $host);
        $apiBase = rtrim((string) ($input->getOption('api-base') ?: $host), '/');

        if (!preg_match('#^https?://#', $apiBase)) {
            $apiBase = 'https://' . $apiBase;
        }

        $io->title('Magento2 attribute-option mapping reconciliation');
        $io->definitionList(
            ['Credential id' => (string) $credential['id']],
            ['Host' => $host],
            ['apiUrl (mapping key)' => $apiUrlKey],
            ['API base' => $apiBase],
            ['Mode' => $apply ? 'APPLY (will write)' : 'dry-run (read-only)']
        );

        $attributes = $input->getOption('attribute') ?: $this->mappedAttributes($apiUrlKey);
        if (!$attributes) {
            $io->warning('No option mappings found for this host. Nothing to do.');

            return Command::SUCCESS;
        }

        $token = (string) $credential['authToken'];
        $store = (string) $input->getOption('store');

        $scanned = $okCount = 0;
        $updates = [];           // list<array{id:int, code:string, old:string, new:string}>
        $inserts = [];           // list<array{code:string, attr:string, new:string}>
        $ambiguous = [];         // list<array{0:string,1:string}>  [code(attr), current]
        $magentoMissing = [];    // list<string> code(attr)
        $staleUnresolved = [];   // list<array{0:int,1:string,2:string}> id, code(attr), old

        foreach ($attributes as $attribute) {
            $attribute = strtolower((string) $attribute);

            try {
                [$validIds, $exactMap, $slugMap] = $this->fetchMagentoOptionIndex($apiBase, $store, $attribute, $token);
            } catch (\RuntimeException $e) {
                $io->warning(sprintf('Skipping attribute "%s": Magento API call failed: %s', $attribute, $e->getMessage()));
                continue;
            }

            $existing = $this->existingMappings($apiUrlKey, $attribute);

            foreach ($this->akeneoOptionCodes($attribute) as $optionCode) {
                $scanned++;
                $label = $optionCode . '(' . $attribute . ')';
                $row = $existing[mb_strtolower($optionCode)] ?? null;
                $candidate = $this->matchBySlug($optionCode, $exactMap, $slugMap);

                if ($row !== null) {
                    $externalId = trim((string) $row['externalId']);

                    if ($externalId !== '' && isset($validIds[$externalId])) {
                        $okCount++;
                        continue;
                    }

                    if (is_string($candidate)) {
                        $updates[] = ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'old' => $externalId, 'new' => $candidate];
                    } elseif ($candidate === false) {
                        $ambiguous[] = [$label, $externalId];
                    } else {
                        $staleUnresolved[] = [(int) $row['id'], (string) $row['code'], $externalId];
                    }

                    continue;
                }

                if (is_string($candidate)) {
                    $inserts[] = ['code' => $label, 'attr' => $attribute, 'new' => $candidate];
                } elseif ($candidate === false) {
                    $ambiguous[] = [$label, '(missing)'];
                } else {
                    $magentoMissing[] = $label;
                }
            }
        }

        $this->report($io, $scanned, $okCount, $updates, $inserts, $ambiguous, $staleUnresolved, $magentoMissing);

        $changes = count($updates) + count($inserts);
        if ($changes === 0) {
            $io->success('Every Akeneo option that exists in Magento is correctly mapped. Nothing to repair.');

            return Command::SUCCESS;
        }

        if (!$apply) {
            $io->note(sprintf('Dry-run: %d mapping(s) would be repaired and %d created. Re-run with --apply to persist.', count($updates), count($inserts)));

            return Command::SUCCESS;
        }

        [$updated, $created] = $this->applyChanges($updates, $inserts, $apiUrlKey);
        $io->success(sprintf('Repaired %d and created %d mapping(s).', $updated, $created));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \RuntimeException when no single credential can be resolved
     */
    private function resolveCredential(?string $credentialId): array
    {
        if ($credentialId !== null) {
            $row = $this->connection->fetchAssociative(
                'SELECT id, hostName, authToken, authMethod FROM wk_magento2_credentials_mapping WHERE id = :id',
                ['id' => (int) $credentialId]
            );

            if (!$row) {
                throw new \RuntimeException(sprintf('No credential with id %s.', $credentialId));
            }

            return $row;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, hostName, authToken, authMethod, active, defaultSet FROM wk_magento2_credentials_mapping WHERE active = 1'
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

    /**
     * @return list<string>
     */
    private function mappedAttributes(string $apiUrlKey): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT DISTINCT SUBSTRING_INDEX(SUBSTRING_INDEX(code, '(', -1), ')', 1) AS attr
             FROM wk_magento2_data_mapping
             WHERE entityType = :type AND apiUrl = :url
             ORDER BY attr",
            ['type' => self::ENTITY_TYPE, 'url' => $apiUrlKey]
        );

        return array_map(static fn (array $r): string => (string) $r['attr'], $rows);
    }

    /**
     * @return list<string>
     */
    private function akeneoOptionCodes(string $attribute): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT o.code
             FROM pim_catalog_attribute_option o
             JOIN pim_catalog_attribute a ON a.id = o.attribute_id
             WHERE a.code = :attr',
            ['attr' => $attribute]
        );

        return array_map(static fn (array $r): string => (string) $r['code'], $rows);
    }

    /**
     * Existing option mappings for an attribute, keyed by lower-cased option code.
     *
     * @return array<string, array{id: int|string, code: string, externalId: string}>
     */
    private function existingMappings(string $apiUrlKey, string $attribute): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, code, externalId
             FROM wk_magento2_data_mapping
             WHERE entityType = :type AND apiUrl = :url AND code LIKE :like",
            ['type' => self::ENTITY_TYPE, 'url' => $apiUrlKey, 'like' => '%(' . $attribute . ')']
        );

        $suffix = '(' . $attribute . ')';
        $map = [];
        foreach ($rows as $row) {
            $code = (string) $row['code'];
            $optionCode = str_ends_with($code, $suffix) ? substr($code, 0, -strlen($suffix)) : $code;
            $map[mb_strtolower($optionCode)] ??= $row;
        }

        return $map;
    }

    /**
     * Builds three lookups for a Magento attribute: the set of valid ids, an
     * exact lower-cased label index and a slugified label index.
     *
     * @return array{0: array<string, true>, 1: array<string, list<string>>, 2: array<string, list<string>>}
     *
     * @throws \RuntimeException on transport or HTTP error
     */
    private function fetchMagentoOptionIndex(string $apiBase, string $store, string $attribute, string $token): array
    {
        $url = sprintf('%s/rest/%s/V1/products/attributes/%s/options', $apiBase, rawurlencode($store), rawurlencode($attribute));
        $options = $this->httpGetJson($url, $token);

        $validIds = $exactMap = $slugMap = [];

        foreach ($options as $option) {
            $value = isset($option['value']) ? trim((string) $option['value']) : '';
            if ($value === '') {
                continue;
            }

            $validIds[$value] = true;

            $label = isset($option['label']) ? trim((string) $option['label']) : '';
            if ($label === '') {
                continue;
            }

            $exactMap[mb_strtolower($label)][] = $value;
            $slugMap[$this->slug($label)][] = $value;
        }

        return [$validIds, $exactMap, $slugMap];
    }

    /**
     * Resolves the Magento id for an option code.
     *
     * @param array<string, list<string>> $exactMap
     * @param array<string, list<string>> $slugMap
     *
     * @return string|false|null the id on a unique match, false when ambiguous, null when not found
     */
    private function matchBySlug(string $optionCode, array $exactMap, array $slugMap): string|false|null
    {
        foreach ([mb_strtolower($optionCode) => $exactMap, $this->slug($optionCode) => $slugMap] as $key => $map) {
            if (!isset($map[$key])) {
                continue;
            }

            $ids = array_values(array_unique($map[$key]));

            return count($ids) === 1 ? $ids[0] : false;
        }

        return null;
    }

    /**
     * @param list<array{id: int, code: string, old: string, new: string}> $updates
     * @param list<array{code: string, attr: string, new: string}>          $inserts
     *
     * @return array{0: int, 1: int} [updated, created]
     */
    private function applyChanges(array $updates, array $inserts, string $apiUrlKey): array
    {
        $updated = $created = 0;
        $this->connection->beginTransaction();

        try {
            foreach ($updates as $u) {
                $updated += (int) $this->connection->executeStatement(
                    'UPDATE wk_magento2_data_mapping SET externalId = :new WHERE id = :id AND externalId = :old',
                    ['new' => $u['new'], 'id' => $u['id'], 'old' => $u['old']]
                );
            }

            foreach ($inserts as $i) {
                $created += (int) $this->connection->executeStatement(
                    "INSERT INTO wk_magento2_data_mapping
                        (entityType, code, magentoCode, externalId, relatedId, jobInstanceId, storeViewCode, apiUrl, extras)
                     VALUES (:type, :code, :code, :ext, NULL, 0, NULL, :url, NULL)",
                    ['type' => self::ENTITY_TYPE, 'code' => $i['code'], 'ext' => $i['new'], 'url' => $apiUrlKey]
                );
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }

        return [$updated, $created];
    }

    private function slug(string $value): string
    {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($transliterated !== false) {
            $value = $transliterated;
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;

        return trim($value, '_');
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws \RuntimeException on transport or non-2xx HTTP status
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
            throw new \RuntimeException($error !== '' ? $error : 'transport error');
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('HTTP %d: %s', $status, substr((string) $body, 0, 300)));
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('unexpected (non-array) JSON response');
        }

        return $decoded;
    }

    /**
     * @param list<array{id: int, code: string, old: string, new: string}> $updates
     * @param list<array{code: string, attr: string, new: string}>          $inserts
     * @param list<array{0: string, 1: string}>                             $ambiguous
     * @param list<array{0: int, 1: string, 2: string}>                     $staleUnresolved
     * @param list<string>                                                  $magentoMissing
     */
    private function report(
        SymfonyStyle $io,
        int $scanned,
        int $okCount,
        array $updates,
        array $inserts,
        array $ambiguous,
        array $staleUnresolved,
        array $magentoMissing
    ): void {
        $io->section('Summary');
        $io->table(
            ['Status', 'Count'],
            [
                ['Akeneo options scanned', (string) $scanned],
                ['already mapped & valid (PUT)', (string) $okCount],
                ['stale -> will be repaired', (string) count($updates)],
                ['unmapped but in Magento -> will be created', (string) count($inserts)],
                ['ambiguous slug (skipped)', (string) count($ambiguous)],
                ['stale, no slug match (skipped)', (string) count($staleUnresolved)],
                ['absent in Magento (export will create)', (string) count($magentoMissing)],
            ]
        );

        if ($updates) {
            $io->section('Repair (stale externalId -> Magento id)');
            $io->table(
                ['row id', 'code', 'old', 'new'],
                array_map(static fn (array $u): array => [(string) $u['id'], $u['code'], $u['old'], $u['new']], $updates)
            );
        }

        if ($inserts) {
            $io->section('Create (new mapping -> Magento id)');
            $io->table(
                ['code', 'new'],
                array_map(static fn (array $i): array => [$i['code'], $i['new']], $inserts)
            );
        }

        if ($ambiguous) {
            $io->section('Ambiguous (multiple Magento options share the slug — skipped)');
            $io->table(['code', 'current'], array_map(static fn (array $r): array => [$r[0], $r[1]], $ambiguous));
        }

        if ($staleUnresolved) {
            $io->section('Stale & unmatched (kept as-is — review manually)');
            $io->table(['row id', 'code', 'old'], array_map(static fn (array $r): array => [(string) $r[0], $r[1], $r[2]], $staleUnresolved));
        }
    }
}

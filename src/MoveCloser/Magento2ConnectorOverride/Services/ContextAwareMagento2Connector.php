<?php

declare(strict_types=1);

namespace MoveCloser\Magento2ConnectorOverride\Services;

use Doctrine\DBAL\Connection;
use Webkul\Magento2Bundle\Services\Magento2Connector;

/**
 * Connector service override aligning url_key generation with Magento.
 *
 * @author MoveCloser
 */
class ContextAwareMagento2Connector extends Magento2Connector
{
    // Plain Latin-ASCII would also rewrite symbols ("®" -> "(R)") and change keys without accents
    private const LATIN_TO_ASCII = '[:Latin:] Latin-ASCII';

    private ?\Transliterator $latinToAscii = null;

    private Connection $connection;

    /** @var array<string, list<string>>|null */
    private ?array $metricOptions = null;

    /**
     * The parent keeps its entity manager private; OverrideWebkulWritersPass passes the connection.
     */
    public function setConnection(Connection $connection): void
    {
        $this->connection = $connection;
    }

    /**
     * {@inheritdoc}
     *
     * The parent's values - each metric of `custom_fields` rounded to 2 places, with its unit when
     * `metric_selection` is on - read from raw_values (a variant inherits from its models) instead of
     * hydrating every product of the PIM: 6-7 minutes of every structure export on 64 k products.
     *
     * @return array<string, list<string>>
     * @throws \Doctrine\DBAL\Exception
     */
    public function attributesAxesOptions()
    {
        if (null !== $this->metricOptions) {
            return $this->metricOptions;
        }

        $customFields = array_values((array) ($this->getOtherMappings()['custom_fields'] ?? []));
        $withUnit = 'true' == ($this->getSettings()['metric_selection'] ?? null);
        $families = [];
        $rows = [] === $customFields ? [] : $this->connection->fetchAllNumeric(
            "SELECT fa.family_id, a.code FROM pim_catalog_family_attribute fa
               JOIN pim_catalog_attribute a ON a.id = fa.attribute_id
              WHERE a.attribute_type = 'pim_catalog_metric' AND a.code IN (:codes)",
            ['codes' => $customFields],
            ['codes' => Connection::PARAM_STR_ARRAY]
        );

        foreach ($rows as [$familyId, $code]) {
            $families[(int) $familyId][] = (string) $code;
        }

        $codes = array_values(array_unique(array_merge([], ...array_values($families))));
        $columns = [];

        foreach ($codes as $code) {
            // stała ścieżka i jeden wiersz na produkt: dokument raw_values czytany raz, a nie raz na metrykę
            $path = $this->connection->quote(sprintf('$."%s"."<all_channels>"."<all_locales>"', $code));
            $columns[] = sprintf('COALESCE(JSON_EXTRACT(p.raw_values, %1$s), JSON_EXTRACT(pm.raw_values, %1$s), JSON_EXTRACT(root.raw_values, %1$s))', $path);
        }

        $rows = [] === $codes ? [] : $this->connection->fetchAllNumeric(
            'SELECT p.family_id, ' . implode(', ', $columns) . ' FROM pim_catalog_product p
               LEFT JOIN pim_catalog_product_model pm ON pm.id = p.product_model_id
               LEFT JOIN pim_catalog_product_model root ON root.id = pm.parent_id
              WHERE p.family_id IN (:families)',
            ['families' => array_keys($families)],
            ['families' => Connection::PARAM_INT_ARRAY]
        );
        $column = array_flip($codes);
        $options = [];

        foreach ($rows as $row) {
            foreach ($families[(int) $row[0]] as $code) {
                $metric = json_decode((string) $row[$column[$code] + 1], true);

                if (is_array($metric)) {
                    $options[$code][] = round((float) ($metric['amount'] ?? 0), 2) . ' ' . ($withUnit ? (string) ($metric['unit'] ?? '') : '');
                }
            }
        }

        return $this->metricOptions = array_map(static fn (array $values): array => array_values(array_unique($values)), $options);
    }

    /**
     * {@inheritdoc}
     *
     * libiconv turns "ó" into "'o" and Magento makes the mark a hyphen ("sk-orzane"), so accents go first.
     * Iconv would spell out "™", "®" and "©", so they are dropped before it.
     * The regex mirrors \Magento\Framework\Filter\TranslitUrl, so Magento stores the key as sent.
     *
     * @throws \RuntimeException
     */
    public function formatUrlKey($string)
    {
        $string = str_replace(
            ["'", ' ø ', '×', '™', '®', '©'],
            ['', ' ', 'x', ' ', ' ', ' '],
            (string) $string
        );

        $transliterated = $this->getLatinToAscii()->transliterate($string);
        if (false !== $transliterated) {
            $string = $transliterated;
        }

        // without //ignore one unmappable character ("⌀") fails the whole string
        $ascii = iconv('UTF-8', 'ascii//ignore//translit', $string);
        if (false !== $ascii) {
            $string = $ascii;
        }

        return trim(strtolower((string) preg_replace('#[^0-9a-z]+#i', '-', $string)), '-');
    }

    /**
     * @return \Transliterator
     * @throws \RuntimeException
     */
    private function getLatinToAscii(): \Transliterator
    {
        if (null !== $this->latinToAscii) {
            return $this->latinToAscii;
        }

        $transliterator = \Transliterator::create(self::LATIN_TO_ASCII);
        if (null === $transliterator) {
            throw new \RuntimeException(sprintf('Cannot create ICU transliterator "%s".', self::LATIN_TO_ASCII));
        }

        return $this->latinToAscii = $transliterator;
    }
}

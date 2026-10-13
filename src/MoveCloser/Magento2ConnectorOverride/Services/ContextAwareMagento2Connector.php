<?php

declare(strict_types=1);

namespace MoveCloser\Magento2ConnectorOverride\Services;

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

    /**
     * {@inheritdoc}
     *
     * libiconv turns "ó" into "'o" and Magento makes the mark a hyphen ("sk-orzane"), so accents go first.
     * The regex mirrors \Magento\Framework\Filter\TranslitUrl, so Magento stores the key as sent.
     *
     * @throws \RuntimeException
     */
    public function formatUrlKey($string)
    {
        $string = str_replace(["'", ' ø ', '×'], ['', ' ', 'x'], (string) $string);

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

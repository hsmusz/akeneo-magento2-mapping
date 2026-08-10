<?php

declare(strict_types=1);

namespace MoveCloser\Magento2ConnectorOverride\Media;

/**
 * Wspólna normalizacja nazw plików galerii, używana przy dopasowywaniu wartości PIM do zdjęć już
 * obecnych w Magento.
 *
 * Ta sama grafika bywa w obu systemach zapisana pod inną nazwą, bo każdy dokleja własny prefiks:
 * PIM sha1 swojego magazynu plików, Magento identyfikator z mechanizmu, którym zdjęcie wgrano
 * (starsze wgrania mają prefiks krótszy niż sha1), a przy kolizji nazw dokłada jeszcze sufiks "_N".
 * Po odcięciu obu zostaje oryginalna nazwa pliku, po której da się je zestawić.
 *
 * @author MoveCloser
 */
final class MediaFileName
{
    /**
     * Wiodący prefiks-hash: sam heks, minimum 8 znaków, z co najmniej jedną literą - warunek litery
     * chroni nazwy zaczynające się od samych cyfr (EAN) przed obcięciem.
     */
    private const HASH_PREFIX = '/^(?=[0-9a-f]*[a-f])[0-9a-f]{8,40}_/';

    private const COLLISION_SUFFIX = '/_\d+(\.[^.]+)$/';

    /**
     * Nazwa sprowadzona do postaci porównywalnej między PIM a Magento.
     */
    public static function normalize(string $fileName): string
    {
        $stripped = (string) preg_replace(self::HASH_PREFIX, '', $fileName, 1);

        return (string) preg_replace(self::COLLISION_SUFFIX, '$1', $stripped);
    }

    /**
     * Nazwa bez samego sufiksu kolizyjnego dokładanego przez Magento.
     */
    public static function withoutCollisionSuffix(string $fileName): string
    {
        return (string) preg_replace(self::COLLISION_SUFFIX, '$1', $fileName);
    }
}

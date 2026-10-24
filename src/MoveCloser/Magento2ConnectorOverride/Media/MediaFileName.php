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

    /**
     * Magento numeruje kolizje od 1 w górę, bez zer wiodących. Zawężenie do tej postaci chroni
     * człony nazwy będące fragmentem kodu produktu, np. `..._01_02.png`.
     */
    private const COLLISION_SUFFIX = '/_[1-9][0-9]?(\.[^.]+)$/';

    /**
     * Sufiks odcinany tylko z nazwy pliku Magento, gdy wynik ma się zgodzić z nazwą PIM - zdjęcie
     * wspólne dla setek SKU dostaje np. `_109`, a upload przez tmp potrafi dołożyć dwa (`_1_1`).
     */
    private const MAGENTO_COLLISION_SUFFIX = '/_[1-9][0-9]*(\.[^.]+)$/';

    /**
     * Nazwa sprowadzona do postaci porównywalnej między PIM a Magento.
     */
    public static function normalize(string $fileName): string
    {
        return self::fold((string) preg_replace(self::COLLISION_SUFFIX, '$1', self::withoutHashPrefixes($fileName)));
    }

    /**
     * Jak normalize(), ale z końcowym "_N" - w nazwie PIM to część oryginału (`Hero_1` i `Hero_2`
     * to różne zdjęcia).
     */
    public static function sibling(string $fileName): string
    {
        return self::fold(self::withoutHashPrefixes($fileName));
    }

    /**
     * Nazwa bez samego sufiksu kolizyjnego dokładanego przez Magento.
     */
    public static function withoutCollisionSuffix(string $fileName): string
    {
        return (string) preg_replace(self::MAGENTO_COLLISION_SUFFIX, '$1', $fileName);
    }

    /**
     * Paruje nazwy PIM z plikami galerii Magento od poziomu najpewniejszego: nazwa dokładna, bez
     * jednego i bez dwóch sufiksów kolizji Magento, rodzeństwo `_N`, nazwa goła. Plik trafia do co
     * najwyżej jednej nazwy, więc dopasowanie przybliżone nie wypiera dokładnego, a nadmiar nazw
     * zostaje bez pary (upload).
     *
     * @param list<string>       $names nazwy PIM
     * @param array<int, string> $files klucz => nazwa pliku w Magento (basename)
     * @return array<string, int> nazwa PIM => klucz pliku
     */
    public static function match(array $names, array $files): array
    {
        $names = array_values(array_unique($names));
        sort($names);
        $stripped = static fn (string $file): array => [
            $file,
            $once = self::withoutCollisionSuffix($file),
            self::withoutCollisionSuffix($once),
        ];
        $levels = [
            [static fn (string $name): string => $name, static fn (string $file): array => [$stripped($file)[0]]],
            [static fn (string $name): string => $name, static fn (string $file): array => [$stripped($file)[1]]],
            [static fn (string $name): string => $name, static fn (string $file): array => [$stripped($file)[2]]],
            [self::sibling(...), static fn (string $file): array => array_map(self::sibling(...), $stripped($file))],
            [self::normalize(...), static fn (string $file): array => [self::normalize($file)]],
        ];
        $matched = [];

        foreach ($levels as [$nameKey, $fileKeys]) {
            $byKey = [];

            foreach ($files as $fileKey => $file) {
                foreach (array_unique($fileKeys($file)) as $key) {
                    $byKey[$key][] = $fileKey;
                }
            }

            foreach ($names as $name) {
                if (isset($matched[$name])) {
                    continue;
                }

                foreach ($byKey[$nameKey($name)] ?? [] as $fileKey) {
                    if (isset($files[$fileKey])) {
                        $matched[$name] = $fileKey;
                        unset($files[$fileKey]);
                        break;
                    }
                }
            }
        }

        return $matched;
    }

    private static function withoutHashPrefixes(string $fileName): string
    {
        // Prefiksy potrafią się nawarstwiać: plik pobrany z Magento i wstawiony do magazynu PIM
        // niesie identyfikator z Magento pod hashem PIM. Zdejmowane są wszystkie.
        while (1 === preg_match(self::HASH_PREFIX, $fileName)) {
            $fileName = (string) preg_replace(self::HASH_PREFIX, '', $fileName, 1);
        }

        return $fileName;
    }

    private static function fold(string $name): string
    {
        // Ta sama grafika bywa wgrywana raz z myślnikami, raz z podkreśleniami, raz inną wielkością
        // liter - dla dopasowania to jedna nazwa.
        return strtolower((string) preg_replace('/[-_]+/', '_', $name));
    }
}

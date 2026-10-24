<?php

declare(strict_types=1);

/**
 * Testy dopasowania nazw zdjęć PIM do galerii Magento. Bez PHPUnit - pakiet nie ma zależności dev.
 *
 * Uruchomienie: php tests/media-file-name-selftest.php
 */

require __DIR__ . '/../src/MoveCloser/Magento2ConnectorOverride/Media/MediaFileName.php';

use MoveCloser\Magento2ConnectorOverride\Media\MediaFileName;

$failed = [];

function check(string $name, mixed $expected, mixed $actual): void
{
    global $failed;

    if (is_array($expected) && is_array($actual)) {
        ksort($expected);
        ksort($actual);
    }

    if ($expected === $actual) {
        printf("  ok   %s\n", $name);

        return;
    }

    $failed[] = $name;
    printf("  FAIL %s\n       oczekiwano %s, jest %s\n", $name, json_encode($expected), json_encode($actual));
}

check(
    'nazwa dokładna wygrywa z gołą',
    ['aaaaaaaa_foo.jpg' => 2],
    MediaFileName::match(['aaaaaaaa_foo.jpg'], [1 => 'bbbbbbbb_foo.jpg', 2 => 'aaaaaaaa_foo.jpg'])
);
check(
    'dokładne trafienie późniejszej nazwy nie jest zabierane przez przybliżone wcześniejszej',
    ['aaaaaaaa_foo.jpg' => 2, 'bbbbbbbb_foo.jpg' => 1],
    MediaFileName::match(['aaaaaaaa_foo.jpg', 'bbbbbbbb_foo.jpg'], [1 => 'bbbbbbbb_foo.jpg', 2 => 'cccccccc_foo.jpg'])
);
check(
    'rodzeństwo _1 / _2: jedyny plik idzie do swojego numeru',
    ['bbbbbbbb2_4932499014__Hero_1.jpg' => 10],
    MediaFileName::match(['aaaaaaaa1_4932499014__Hero_2.jpg', 'bbbbbbbb2_4932499014__Hero_1.jpg'], [10 => 'cccccccc_4932499014__Hero_1.jpg'])
);
check(
    'sufiks kolizji Magento powyżej 99',
    ['cf4e5f96a0_App_1_scaled.jpg' => 5],
    MediaFileName::match(['cf4e5f96a0_App_1_scaled.jpg'], [5 => 'cf4e5f96a0_App_1_scaled_109.jpg'])
);
check(
    'dwa sufiksy Magento (tmp + katalog): _296_1_1, a własne _1 w nazwie PIM ma pierwszeństwo',
    ['abcdef12_x_296.jpg' => 7, 'abcdef12_y_1.jpg' => 8],
    MediaFileName::match(['abcdef12_x_296.jpg', 'abcdef12_y.jpg', 'abcdef12_y_1.jpg'], [7 => 'abcdef12_x_296_1_1.jpg', 8 => 'abcdef12_y_1_1.jpg'])
);
check(
    'kolizja na nazwie z _N: foo_1 -> foo_1_1, nie foo_2',
    ['abcdef12_foo_1.jpg' => 1],
    MediaFileName::match(['abcdef12_foo_1.jpg'], [2 => 'abcdef12_foo_2.jpg', 1 => 'abcdef12_foo_1_1.jpg'])
);
check(
    'plik trafia do jednej nazwy, nadmiar bez pary',
    1,
    count(MediaFileName::match(['aaaaaaaa_foo.jpg', 'bbbbbbbb_foo.jpg'], [1 => 'cccccccc_foo.jpg']))
);
check(
    'goła nazwa łączy prefiksy, myślniki i wielkość liter',
    ['9f8e7d6c5b_2024_06_X-Hero.JPG' => 3],
    MediaFileName::match(['9f8e7d6c5b_2024_06_X-Hero.JPG'], [3 => 'abc12345_2024_06_x_hero_4.jpg'])
);
check('normalize: prefiks z samych cyfr (EAN) zostaje', '5901234123457_foo.jpg', MediaFileName::normalize('5901234123457_foo.jpg'));
check('normalize: człon kodu z zerem wiodącym zostaje', 'x_01_02.png', MediaFileName::normalize('abcdef12_x_01_02.png'));
check('sibling: _N zostaje, prefiksy zdjęte', 'x_hero_2.jpg', MediaFileName::sibling('abcdef12_1234abcd_X__Hero_2.jpg'));

if ([] !== $failed) {
    printf("\nNIEUDANE: %d\n", count($failed));
    exit(1);
}

echo "OK\n";

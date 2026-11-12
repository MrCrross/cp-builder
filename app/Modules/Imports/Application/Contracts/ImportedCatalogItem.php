<?php

namespace App\Modules\Imports\Application\Contracts;

/**
 * Позиция каталога, нормализованная для apply. Единый fingerprint-формат
 * используется и импортёром каталога, и определением action add/change/skip
 * на этапе parse (preview совпадает с apply).
 *
 * Картинка: YML хранит внешний URL как есть; CommerceML скачивает http(s)
 * URL в storage, поэтому fingerprint считается по детерминированному
 * итоговому пути (normalizeImage) — apply не расходится с preview.
 */
final readonly class ImportedCatalogItem
{
    /**
     * @param  list<array{name: string, value: string}>  $properties
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public ?string $unit,
        /** Десятичная строка, копейки совместимы с Money VO (нормализация — в импортёре). */
        public string $price,
        public string $currency,
        public array $properties = [],
        public ?string $image = null,
        public ?string $sourceUrl = null,
    ) {}

    /**
     * @param  list<array{name: string, value: string}>  $properties
     */
    public static function fingerprint(string $name, ?string $description, ?string $unit, string $price, string $currency, array $properties = [], ?string $image = null, ?string $sourceUrl = null): string
    {
        return md5(implode('|', [
            mb_strtolower(trim($name)),
            $description === null ? '' : mb_strtolower(trim($description)),
            $unit === null ? '' : mb_strtolower(trim($unit)),
            $price,
            $currency,
            self::canonicalProperties($properties),
            $image === null ? '' : trim($image),
            $sourceUrl === null ? '' : trim($sourceUrl),
        ]));
    }

    /**
     * Итоговое значение поля image в каталоге: для CommerceML http(s) URL
     * картинка скачивается в storage по детерминированному пути (зависит
     * только от URL), для остальных случаев — исходная строка как есть.
     */
    public static function normalizeImage(string $source, ?string $image): ?string
    {
        if ($image === null || trim($image) === '' || $source !== 'commerceml') {
            return $image === null ? null : trim($image);
        }

        if (! preg_match('#^https?://#i', $image)) {
            return trim($image); // относительный путь из выгрузки — как есть
        }

        return 'imports/commerceml/'.hash('sha256', trim($image)).self::imageExtension($image);
    }

    /**
     * @param  list<array{name: string, value: string}>  $properties
     */
    private static function canonicalProperties(array $properties): string
    {
        $pairs = array_map(
            fn (array $pair): string => mb_strtolower(trim($pair['name']))."\n".mb_strtolower(trim($pair['value'])),
            $properties,
        );
        sort($pairs);

        return implode("\n", $pairs);
    }

    /**
     * Расширение файла картинки из URL (белый список); без него — .jpg.
     */
    private static function imageExtension(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('/\.(jpe?g|png|webp|gif)$/i', $path, $matches) === 1) {
            return strtolower($matches[0]);
        }

        return '.jpg';
    }
}

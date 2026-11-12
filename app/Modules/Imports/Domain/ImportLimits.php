<?php

namespace App\Modules\Imports\Domain;

/**
 * Лимиты безопасного потокового разбора (план §9.5). Парсер только
 * XMLReader: DOM/SimpleXML целиком запрещены — 197M-эталон YML должен
 * помещаться в согласованный memory budget.
 */
final class ImportLimits
{
    /** Максимальный размер одного загружаемого файла (512 МБ). */
    public const int MAX_FILE_BYTES = 536870912;

    /** Максимальная глубина вложенности элементов. */
    public const int MAX_DEPTH = 64;

    /** Максимум XML-узлов в одном файле (защита от entity/bomb-раздувания). */
    public const int MAX_NODE_COUNT = 5000000;

    /** Максимальная длина одного текстового значения, символов. */
    public const int MAX_TEXT_LENGTH = 65536;

    /** Максимум позиций в одном job'е (несколько файлов-чанков суммарно). */
    public const int MAX_ITEMS_PER_JOB = 500000;

    /** Максимум характеристик у одной позиции. */
    public const int MAX_PROPERTIES_PER_ITEM = 100;

    /** Сканируемый префикс файла на предмет <!ENTITY (XXE fail-fast). */
    public const int PROLOG_SCAN_BYTES = 65536;

    public static function describe(string $constant): string
    {
        return match ($constant) {
            'MAX_DEPTH' => 'глубина вложенности элементов ('.self::MAX_DEPTH.')',
            'MAX_NODE_COUNT' => 'количество узлов ('.self::MAX_NODE_COUNT.')',
            'MAX_TEXT_LENGTH' => 'длина текстового значения ('.self::MAX_TEXT_LENGTH.')',
            'MAX_ITEMS_PER_JOB' => 'количество позиций в одном импорте ('.self::MAX_ITEMS_PER_JOB.')',
            'MAX_PROPERTIES_PER_ITEM' => 'количество характеристик у позиции ('.self::MAX_PROPERTIES_PER_ITEM.')',
            default => $constant,
        };
    }
}

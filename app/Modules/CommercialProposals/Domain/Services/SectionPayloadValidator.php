<?php

namespace App\Modules\CommercialProposals\Domain\Services;

use App\Modules\CommercialProposals\Domain\Enums\SectionType;
use Illuminate\Validation\ValidationException;

/**
 * Strict-валидация typed payload секций (план §8.2, §9.3): только известные
 * типы, только известные поля, жёсткие лимиты длины. Rich text custom-блоков —
 * Editor.js block JSON с allowlist'ом типов; URL-схемы проверяются рендерером
 * на выводе, здесь — только структура.
 */
final class SectionPayloadValidator
{
    private const MAX_STRING = 10_000;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> нормализованный payload
     *
     * @throws ValidationException
     */
    public static function validate(SectionType $type, array $payload): array
    {
        $validator = validator($payload, self::rules($type));

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private static function rules(SectionType $type): array
    {
        $short = 'nullable|string|max:500';
        $editorBlocks = 'nullable|array|max:100';
        $editorBlockShape = [
            'blocks.*.type' => 'required|in:paragraph,header,list,table',
            'blocks.*.data' => 'required|array',
        ];

        return match ($type) {
            SectionType::Stages => [
                'title' => $short,
                'stages' => 'required|array|max:50',
                'stages.*.title' => 'required|string|max:500',
                'stages.*.duration' => 'required|string|max:500',
            ],
            SectionType::Custom => [
                'title' => $short,
                'blocks' => $editorBlocks,
                ...$editorBlockShape,
                'blocks.*.data.text' => 'nullable|string|max:'.self::MAX_STRING,
                'blocks.*.data.level' => 'nullable|integer|min:1|max:4',
                'blocks.*.data.style' => 'nullable|in:ordered,unordered',
                'blocks.*.data.items' => 'nullable|array|max:200',
                'blocks.*.data.items.*' => 'string|max:'.self::MAX_STRING,
                'blocks.*.data.withHeadings' => 'nullable|boolean',
                'blocks.*.data.content' => 'nullable|array|max:200',
                'blocks.*.data.content.*' => 'array|max:50',
                'blocks.*.data.content.*.*' => 'nullable|string|max:'.self::MAX_STRING,
            ],
            SectionType::Table => [
                'title' => $short,
                'columns' => 'required|array|min:1|max:20',
                'columns.*.key' => 'required|string|max:100',
                'columns.*.label' => 'required|string|max:500',
                'rows' => 'nullable|array|max:500',
                'rows.*' => 'array|max:20',
                'rows.*.*' => 'nullable|string|max:'.self::MAX_STRING,
                // Одна ценовая колонка; включение в общий итог — только при ней.
                'price_column' => 'nullable|string|max:100',
                'include_in_total' => 'nullable|boolean',
            ],
            SectionType::License => [
                'title' => $short,
                'items' => 'required|array|min:1|max:100',
                'items.*.name' => 'required|string|max:500',
                'items.*.period' => 'required|string|max:500',
                'items.*.price' => 'required|string|regex:/^\d+(\.\d{1,2})?$/',
            ],
            SectionType::PriceBadge => [
                'label' => 'required|string|max:500',
                'text' => 'nullable|string|max:'.self::MAX_STRING,
            ],
        };
    }
}

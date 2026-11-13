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

    private const MAX_HTML = 50_000;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> нормализованный payload
     *
     * @throws ValidationException
     */
    public static function validate(SectionType $type, array $payload): array
    {
        $validator = validator($payload, self::rules($type), self::messages());

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
            // Позиции прайса — полноценный блок: строки валидируем здесь
            // структурно, деньги/количества доменными VO — в SaveDraftAction.
            SectionType::Items => [
                'title' => $short,
                'items' => 'nullable|array|max:500',
                'items.*.catalog_item_id' => 'nullable|integer|exists:catalog_items,id',
                'items.*.name' => 'required|string|max:500',
                'items.*.description' => 'nullable|string|max:2000',
                'items.*.unit' => 'nullable|string|max:50',
                'items.*.quantity_min' => 'required|string|regex:/^\d+(\.\d{1,2})?$/',
                'items.*.quantity_max' => 'required|string|regex:/^\d+(\.\d{1,2})?$/',
                'items.*.unit_price' => 'required|string|regex:/^\d+(\.\d{1,2})?$/',
            ],
            SectionType::Stages => [
                'title' => $short,
                'stages' => 'required|array|max:50',
                'stages.*.title' => 'required|string|max:500',
                'stages.*.duration' => 'required|string|max:500',
            ],
            SectionType::Custom => [
                'title' => $short,
                // Новый формат текстового блока: один HTML-документ (санитайзер —
                // EditorJsRenderer::renderHtml). Legacy-блоки ниже сохраняются
                // для ранее созданных документов.
                'html' => 'nullable|string|max:'.self::MAX_HTML,
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
                // Тип содержимого — подсказка UI для ввода (текст/число/деньги);
                // старые документы без type валидны.
                'columns.*.type' => 'nullable|in:text,number,money',
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

    /**
     * Понятные менеджеру сообщения: без технических имён полей и ключей.
     *
     * @return array<string, string>
     */
    private static function messages(): array
    {
        return [
            'title.max' => 'Название блока слишком длинное (максимум 500 символов).',
            'html.max' => 'Текстовый блок слишком большой (максимум 50 000 символов).',

            'items.max' => 'Слишком много строк в блоке (максимум 500).',
            'items.required' => 'Добавьте хотя бы одну строку.',
            'items.*.name.required' => 'Укажите название.',
            'items.*.name.max' => 'Название слишком длинное (максимум 500 символов).',
            'items.*.quantity_min.required' => 'Укажите минимальное количество.',
            'items.*.quantity_min.regex' => 'Количество должно быть числом, например 2 или 1.5.',
            'items.*.quantity_max.required' => 'Укажите максимальное количество.',
            'items.*.quantity_max.regex' => 'Количество должно быть числом, например 2 или 1.5.',
            'items.*.unit_price.required' => 'Укажите цену.',
            'items.*.unit_price.regex' => 'Цена должна быть числом, например 1200.00.',

            'stages.required' => 'Добавьте хотя бы один этап.',
            'stages.*.title.required' => 'Укажите название этапа.',
            'stages.*.title.max' => 'Название этапа слишком длинное (максимум 500 символов).',
            'stages.*.duration.required' => 'Укажите срок этапа.',
            'stages.*.duration.max' => 'Срок этапа слишком длинный (максимум 500 символов).',

            'columns.required' => 'Добавьте хотя бы одну колонку.',
            'columns.min' => 'Добавьте хотя бы одну колонку.',
            'columns.*.key.required' => 'Укажите название колонки.',
            'columns.*.label.required' => 'Укажите название колонки.',
            'columns.*.label.max' => 'Название колонки слишком длинное (максимум 500 символов).',
            'columns.*.type.in' => 'Неизвестный тип колонки.',
            'price_column.max' => 'Ценовая колонка указана некорректно.',

            'items.*.period.required' => 'Укажите период.',
            'items.*.price.required' => 'Укажите стоимость.',
            'items.*.price.regex' => 'Стоимость должна быть числом, например 1200.00.',

            'label.required' => 'Укажите текст примечания.',
        ];
    }
}

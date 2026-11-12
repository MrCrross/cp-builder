<?php

namespace App\Modules\Imports\Domain;

/**
 * Нормализованная позиция внешнего каталога (план §13.1: parser не создаёт
 * Eloquent-записи каталога, только DTO). Поля nullable — файл цены несёт
 * только цену, файл товаров — только товар; staging мержит по external_id.
 *
 * Категории источника не сохраняем (решение владельца): для КП нужны
 * картинка, название, характеристики и ссылка на источник.
 *
 * @phpstan-type ParsedItemPayload array<string, mixed>
 * @phpstan-type PropertyPair array{name: string, value: string}
 */
final readonly class ParsedItem
{
    /**
     * @param  list<PropertyPair>  $properties  Характеристики «название → значение».
     */
    public function __construct(
        public string $externalId,
        public ?string $name = null,
        public ?string $description = null,
        public ?string $unit = null,
        /** Десятичная строка в копейках-совместимом виде, нормализация — на apply через Money VO. */
        public ?string $price = null,
        public ?string $currency = null,
        public ?string $article = null,
        public array $properties = [],
        /** Ссылка на картинку: внешний URL (YML) или путь/URL из 1С (скачивание — на apply). */
        public ?string $image = null,
        /** Ссылка на страницу товара в источнике (YML <url>). */
        public ?string $sourceUrl = null,
        /** Soft-delete из источника (<ПометкаУдаления>true) → deactivate при apply. */
        public bool $delete = false,
    ) {}

    /**
     * @return ParsedItemPayload
     */
    public function toPayload(): array
    {
        return [
            'external_id' => $this->externalId,
            'name' => $this->name,
            'description' => $this->description,
            'unit' => $this->unit,
            'price' => $this->price,
            'currency' => $this->currency,
            'article' => $this->article,
            'properties' => $this->properties,
            'image' => $this->image,
            'source_url' => $this->sourceUrl,
            'delete' => $this->delete,
        ];
    }

    /**
     * @param  ParsedItemPayload  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            externalId: (string) $payload['external_id'],
            name: $payload['name'] ?? null,
            description: $payload['description'] ?? null,
            unit: $payload['unit'] ?? null,
            price: $payload['price'] ?? null,
            currency: $payload['currency'] ?? null,
            article: $payload['article'] ?? null,
            properties: self::normalizeProperties($payload['properties'] ?? []),
            image: $payload['image'] ?? null,
            sourceUrl: $payload['source_url'] ?? null,
            delete: (bool) ($payload['delete'] ?? false),
        );
    }

    /**
     * Слияние чанков одного цикла обмена: заполненные поля нового файла
     * перекрывают старые, пустые — не затирают (прайс дополняет товар).
     */
    public function merge(self $newer): self
    {
        return new self(
            externalId: $this->externalId,
            name: $newer->name ?? $this->name,
            description: $newer->description ?? $this->description,
            unit: $newer->unit ?? $this->unit,
            price: $newer->price ?? $this->price,
            currency: $newer->currency ?? $this->currency,
            article: $newer->article ?? $this->article,
            properties: $newer->properties !== [] ? $newer->properties : $this->properties,
            image: $newer->image ?? $this->image,
            sourceUrl: $newer->sourceUrl ?? $this->sourceUrl,
            delete: $newer->delete || $this->delete,
        );
    }

    /**
     * @return list<PropertyPair>
     */
    private static function normalizeProperties(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $normalized = [];

        foreach ($raw as $pair) {
            if (! is_array($pair)) {
                continue;
            }

            $name = trim((string) ($pair['name'] ?? ''));
            $value = trim((string) ($pair['value'] ?? ''));

            if ($name === '' || $value === '') {
                continue;
            }

            $normalized[] = ['name' => $name, 'value' => $value];
        }

        return $normalized;
    }
}

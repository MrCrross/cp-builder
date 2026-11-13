<?php

namespace App\Modules\Imports\Domain\Services;

/**
 * Словарь свойств CommerceML (план §13.1): наименования свойств и тексты
 * вариантов значений из <Свойства> классификатора. Живёт на уровне job'а —
 * порядок файлов цикла обмена не гарантирует классификатор раньше каталога,
 * поэтому staging накапливает словарь между файлами и персистит его в
 * import_jobs.property_dictionary (словари — сотни записей, не гигабайты).
 */
final class PropertyDictionary
{
    /** @var array<string, string> ID свойства → наименование. */
    private array $propertyNames = [];

    /** @var array<string, string> ID варианта значения → текст. */
    private array $variantValues = [];

    /**
     * @param  array{property_names?: array<string, string>, variant_values?: array<string, string>}|string|null  $state
     */
    public static function fromArray(array|string|null $state): self
    {
        $dictionary = new self;

        if (! is_array($state)) {
            return $dictionary;
        }

        $dictionary->propertyNames = $state['property_names'] ?? [];
        $dictionary->variantValues = $state['variant_values'] ?? [];

        return $dictionary;
    }

    public function addProperty(string $externalId, string $name): void
    {
        if ($externalId !== '' && $name !== '') {
            $this->propertyNames[$externalId] = $name;
        }
    }

    public function addVariant(string $externalId, string $value): void
    {
        if ($externalId !== '' && $value !== '') {
            $this->variantValues[$externalId] = $value;
        }
    }

    public function propertyName(string $externalId): ?string
    {
        return $this->propertyNames[$externalId] ?? null;
    }

    public function variantValue(string $externalId): ?string
    {
        return $this->variantValues[$externalId] ?? null;
    }

    /**
     * @return array{property_names: array<string, string>, variant_values: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'property_names' => $this->propertyNames,
            'variant_values' => $this->variantValues,
        ];
    }
}

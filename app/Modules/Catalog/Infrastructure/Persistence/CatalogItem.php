<?php

namespace App\Modules\Catalog\Infrastructure\Persistence;

use Database\Factories\CatalogItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Позиция номенклатуры — источник defaults для новых строк КП,
 * уже сохранённые документы не меняет (snapshot, план §7.3).
 *
 * @property int $id
 * @property int|null $category_id
 * @property string $name
 * @property string|null $description
 * @property string|null $unit
 * @property string $price
 * @property string $currency
 * @property bool $active
 * @property string|null $source
 * @property string|null $external_id
 * @property list<array{name: string, value: string}>|null $properties
 * @property string|null $image
 * @property string|null $source_url
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read CatalogCategory|null $category
 */
class CatalogItem extends Model
{
    /** @use HasFactory<CatalogItemFactory> */
    use HasFactory;

    protected static function newFactory(): CatalogItemFactory
    {
        return CatalogItemFactory::new();
    }

    protected $table = 'catalog_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'active' => 'boolean',
            'properties' => 'array',
        ];
    }

    /**
     * @return BelongsTo<CatalogCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CatalogCategory::class, 'category_id');
    }
}

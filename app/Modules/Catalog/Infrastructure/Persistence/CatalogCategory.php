<?php

namespace App\Modules\Catalog\Infrastructure\Persistence;

use Database\Factories\CatalogCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Плоская категория номенклатуры (план §8.2); иерархия не подтверждена.
 *
 * @property int $id
 * @property string $name
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CatalogCategory extends Model
{
    /** @use HasFactory<CatalogCategoryFactory> */
    use HasFactory;

    protected static function newFactory(): CatalogCategoryFactory
    {
        return CatalogCategoryFactory::new();
    }

    protected $table = 'catalog_categories';

    protected $guarded = [];

    /**
     * @return HasMany<CatalogItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CatalogItem::class, 'category_id');
    }
}

<?php

namespace Database\Factories;

use App\Modules\Catalog\Infrastructure\Persistence\CatalogCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatalogCategory>
 */
class CatalogCategoryFactory extends Factory
{
    protected $model = CatalogCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
        ];
    }
}

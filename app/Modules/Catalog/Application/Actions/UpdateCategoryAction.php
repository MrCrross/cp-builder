<?php

namespace App\Modules\Catalog\Application\Actions;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Persistence\CatalogCategory;
use App\Support\Audit\Audit;

final class UpdateCategoryAction
{
    /**
     * @param  array{name: string}  $data
     */
    public function execute(User $actor, CatalogCategory $category, array $data): CatalogCategory
    {
        $before = $category->only(['name']);

        $category->fill(['name' => $data['name']]);
        $category->save();

        Audit::record($actor, 'catalog-category.updated', $category, $before, ['name' => $category->name]);

        return $category;
    }
}

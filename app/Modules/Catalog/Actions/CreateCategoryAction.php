<?php

namespace App\Modules\Catalog\Actions;

use App\Models\User;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Support\Audit\Audit;

final class CreateCategoryAction
{
    /**
     * @param  array{name: string}  $data
     */
    public function execute(User $actor, array $data): CatalogCategory
    {
        $category = CatalogCategory::query()->create([
            'name' => $data['name'],
        ]);

        Audit::record($actor, 'catalog-category.created', $category, [], ['name' => $category->name]);

        return $category;
    }
}

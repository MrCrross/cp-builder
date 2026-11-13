<?php

namespace App\Modules\Catalog\Policies;

use App\Models\User;
use App\Modules\Catalog\Models\CatalogCategory;

/**
 * Матрица доступа к категориям номенклатуры (план §9.2):
 * viewer read-only, manager/admin — управление.
 */
class CatalogCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('catalog.view');
    }

    public function view(User $user, CatalogCategory $category): bool
    {
        return $user->can('catalog.view');
    }

    public function create(User $user): bool
    {
        return $user->can('catalog.manage');
    }

    public function update(User $user, CatalogCategory $category): bool
    {
        return $user->can('catalog.manage');
    }

    public function delete(User $user, CatalogCategory $category): bool
    {
        return $user->can('catalog.manage');
    }
}

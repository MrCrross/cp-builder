<?php

namespace App\Modules\Catalog\Authorization;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Persistence\CatalogItem;

/**
 * Матрица доступа к позициям номенклатуры (план §9.2):
 * viewer read-only, manager/admin — управление.
 */
class CatalogItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('catalog.view');
    }

    public function view(User $user, CatalogItem $item): bool
    {
        return $user->can('catalog.view');
    }

    public function create(User $user): bool
    {
        return $user->can('catalog.manage');
    }

    public function update(User $user, CatalogItem $item): bool
    {
        return $user->can('catalog.manage');
    }

    public function delete(User $user, CatalogItem $item): bool
    {
        return $user->can('catalog.manage');
    }
}

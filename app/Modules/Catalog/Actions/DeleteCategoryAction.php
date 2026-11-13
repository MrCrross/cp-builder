<?php

namespace App\Modules\Catalog\Actions;

use App\Models\User;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class DeleteCategoryAction
{
    /**
     * Категория с позициями не удаляется (FK restrict, план §8.3);
     * проверка даёт понятное сообщение вместо ошибки БД.
     */
    public function execute(User $actor, CatalogCategory $category): void
    {
        if ($category->items()->exists()) {
            throw new ConflictHttpException('Категорию нельзя удалить: в ней есть позиции номенклатуры.');
        }

        DB::transaction(function () use ($actor, $category) {
            Audit::record($actor, 'catalog-category.deleted', $category, ['name' => $category->name]);
            $category->delete();
        });
    }
}

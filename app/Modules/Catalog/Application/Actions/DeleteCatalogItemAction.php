<?php

namespace App\Modules\Catalog\Application\Actions;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Persistence\CatalogItem;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class DeleteCatalogItemAction
{
    /**
     * Позиция с историей в КП не удаляется (FK restrict, план §8.3) —
     * доступно только отключение (active=false) через UpdateCatalogItemAction.
     */
    public function execute(User $actor, CatalogItem $item): void
    {
        if (DB::table('proposal_items')->where('catalog_item_id', $item->id)->exists()) {
            throw new ConflictHttpException(
                'Позицию нельзя удалить: она используется в коммерческих предложениях. Отключите её.'
            );
        }

        DB::transaction(function () use ($actor, $item) {
            Audit::record($actor, 'catalog-item.deleted', $item, ['name' => $item->name]);
            $item->delete();
        });
    }
}

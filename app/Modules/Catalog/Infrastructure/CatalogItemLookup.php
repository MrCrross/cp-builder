<?php

namespace App\Modules\Catalog\Infrastructure;

use App\Modules\Catalog\Infrastructure\Persistence\CatalogItem;
use App\Modules\CommercialProposals\Application\Contracts\CatalogItemLookupPort;
use App\Modules\CommercialProposals\Application\Data\CatalogItemSnapshot;

/**
 * Реализация lookup-порта каталога (план §6.1): единственное место,
 * где CommercialProposals встречается с моделью Catalog.
 */
final class CatalogItemLookup implements CatalogItemLookupPort
{
    /**
     * @param  list<int>  $ids
     * @return array<int, CatalogItemSnapshot>
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return CatalogItem::query()
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'unit', 'price'])
            ->mapWithKeys(fn (CatalogItem $item): array => [
                $item->id => new CatalogItemSnapshot(
                    id: $item->id,
                    name: $item->name,
                    unit: $item->unit,
                    price: $item->price,
                ),
            ])
            ->all();
    }
}

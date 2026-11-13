<?php

namespace App\Modules\CommercialProposals\Actions;

use App\Modules\CommercialProposals\Contracts\CatalogItemLookupPort;
use App\Modules\CommercialProposals\Models\Proposal;

/**
 * Отличия snapshot-строк черновика от актуального справочника
 * (план §7.3): явное «обновить из справочника» показывает diff
 * до применения. Только чтение.
 */
final class DraftCatalogDiffAction
{
    public function __construct(
        private readonly CatalogItemLookupPort $catalogItems,
    ) {}

    /**
     * @return list<array{position: int, catalog_item_id: int, name: array{current: string, catalog: string}, unit: array{current: ?string, catalog: ?string}, unit_price: array{current: string, catalog: string}, changed: bool}>
     */
    public function execute(Proposal $proposal): array
    {
        $version = $proposal->draftVersion()->with('items')->first();

        if ($version === null) {
            return [];
        }

        $linked = $version->items->filter(fn ($item) => $item->catalog_item_id !== null)->values();

        if ($linked->isEmpty()) {
            return [];
        }

        $catalog = $this->catalogItems->findByIds(array_values($linked->pluck('catalog_item_id')->map(intval(...))->all()));

        $diff = [];

        foreach ($linked as $item) {
            $snapshot = $catalog[$item->catalog_item_id] ?? null;

            if ($snapshot === null) {
                continue;
            }

            $changes = [
                'name' => ['current' => $item->name, 'catalog' => $snapshot->name],
                'unit' => ['current' => $item->unit, 'catalog' => $snapshot->unit],
                'unit_price' => ['current' => $item->unit_price, 'catalog' => $snapshot->price],
            ];

            $changed = $item->name !== $snapshot->name
                || (string) $item->unit !== (string) $snapshot->unit
                || $item->unit_price !== $snapshot->price;

            $diff[] = [
                'position' => $item->position,
                'catalog_item_id' => $item->catalog_item_id,
                ...$changes,
                'changed' => $changed,
            ];
        }

        return $diff;
    }
}

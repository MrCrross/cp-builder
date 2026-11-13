<?php

namespace App\Modules\Catalog\Actions;

use App\Models\User;
use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\CommercialProposals\Domain\ValueObjects\Money;
use App\Support\Audit\Audit;

final class UpdateCatalogItemAction
{
    /**
     * Изменение позиции не меняет уже сохранённые КП — там snapshot
     * (план §7.3). Явное обновление строк черновика — отдельное действие
     * RefreshDraftItemsFromCatalogAction.
     *
     * @param  array{name: string, category_id?: ?int, description?: ?string, unit?: ?string, price: string, active?: bool}  $data
     */
    public function execute(User $actor, CatalogItem $item, array $data): CatalogItem
    {
        $before = $item->only(['name', 'unit', 'price', 'active']);

        $item->fill([
            'category_id' => $data['category_id'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'unit' => $data['unit'] ?? null,
            'price' => Money::fromDecimalString((string) $data['price'])->toDecimalString(),
            'active' => (bool) ($data['active'] ?? true),
        ]);
        $item->save();

        Audit::record($actor, 'catalog-item.updated', $item, $before, [
            'name' => $item->name,
            'category' => $item->category?->name,
            'price' => $item->price,
            'active' => $item->active,
        ]);

        return $item;
    }
}

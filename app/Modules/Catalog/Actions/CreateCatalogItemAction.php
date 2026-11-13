<?php

namespace App\Modules\Catalog\Actions;

use App\Models\User;
use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\CommercialProposals\Domain\ValueObjects\Money;
use App\Support\Audit\Audit;

final class CreateCatalogItemAction
{
    /**
     * Цена приводится к каноническому виду через Money VO (план §7.2):
     * копейки, half-up, строковый transport.
     *
     * @param  array{name: string, category_id?: ?int, description?: ?string, unit?: ?string, price: string, active?: bool}  $data
     */
    public function execute(User $actor, array $data): CatalogItem
    {
        $item = CatalogItem::query()->create([
            'category_id' => $data['category_id'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'unit' => $data['unit'] ?? null,
            'price' => Money::fromDecimalString((string) $data['price'])->toDecimalString(),
            'active' => (bool) ($data['active'] ?? true),
        ]);

        Audit::record($actor, 'catalog-item.created', $item, [], [
            'name' => $item->name,
            'category' => $item->category?->name,
            'price' => $item->price,
        ]);

        return $item;
    }
}

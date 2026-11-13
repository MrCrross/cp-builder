<?php

namespace App\Modules\CommercialProposals\Domain\Services;

use App\Modules\CommercialProposals\Contracts\CatalogItemLookupPort;
use App\Modules\CommercialProposals\Data\CatalogItemSnapshot;
use App\Modules\CommercialProposals\Domain\ValueObjects\Money;
use App\Modules\CommercialProposals\Domain\ValueObjects\Quantity;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Сборка snapshot-строк позиций КП из редактируемых строк (план §7.2):
 * деньги и количества — только доменные VO (bcmath, half-up на строку),
 * обращение к справочнику — через CatalogItemLookupPort. Общая логика
 * SaveDraftAction (строки из блоков) и CreateDraftProposalAction (строки
 * из items-блоков шаблона).
 *
 * @phpstan-type ItemRowInput array{name: string, description?: ?string, unit?: ?string, quantity_min: string, quantity_max: string, unit_price: string, catalog_item_id?: ?int}
 * @phpstan-type ItemRow array{catalog_item_id: int|null, name: string, description: ?string, unit: ?string, quantity_min: string, quantity_max: string, unit_price: string, amount_min: string, amount_max: string}
 */
final class ItemRowsBuilder
{
    /**
     * @param  list<ItemRowInput>  $input
     * @return list<ItemRow>
     *
     * @throws UnprocessableEntityHttpException
     */
    public function build(array $input): array
    {
        $catalogIds = array_values(array_unique(array_filter(array_map(
            fn (array $row): ?int => isset($row['catalog_item_id']) ? (int) $row['catalog_item_id'] : null,
            $input,
        ))));

        /** @var array<int, CatalogItemSnapshot> $catalog */
        $catalog = app(CatalogItemLookupPort::class)->findByIds($catalogIds);

        $rows = [];

        foreach ($input as $row) {
            $catalogItemId = isset($row['catalog_item_id']) ? (int) $row['catalog_item_id'] : null;

            if ($catalogItemId !== null && ! isset($catalog[$catalogItemId])) {
                throw new UnprocessableEntityHttpException('Позиция справочника не найдена.');
            }

            try {
                $quantityMin = Quantity::fromString((string) $row['quantity_min']);
                $quantityMax = Quantity::fromString((string) $row['quantity_max']);
                $price = Money::fromDecimalString((string) $row['unit_price']);
            } catch (\InvalidArgumentException $e) {
                throw new UnprocessableEntityHttpException($e->getMessage());
            }

            if ($quantityMin->lessThan($quantityMax) === false && $quantityMin->compare($quantityMax) !== 0) {
                throw new UnprocessableEntityHttpException('Минимальное количество не может превышать максимальное.');
            }

            $name = trim((string) $row['name']);
            if ($name === '' || mb_strlen($name) > 500) {
                throw new UnprocessableEntityHttpException('Название позиции обязательно (до 500 символов).');
            }

            $rows[] = [
                'catalog_item_id' => $catalogItemId,
                'name' => $name,
                'description' => $row['description'] ?? null,
                'unit' => $row['unit'] ?? null,
                'quantity_min' => $quantityMin->toDecimalString(),
                'quantity_max' => $quantityMax->toDecimalString(),
                'unit_price' => $price->toDecimalString(),
                'amount_min' => Money::lineAmount($quantityMin->toDecimalString(), $price)->toDecimalString(),
                'amount_max' => Money::lineAmount($quantityMax->toDecimalString(), $price)->toDecimalString(),
            ];
        }

        return $rows;
    }
}

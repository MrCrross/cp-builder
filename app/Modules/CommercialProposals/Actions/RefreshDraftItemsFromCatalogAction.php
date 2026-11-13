<?php

namespace App\Modules\CommercialProposals\Actions;

use App\Models\User;
use App\Modules\CommercialProposals\Contracts\CatalogItemLookupPort;
use App\Modules\CommercialProposals\Domain\Enums\SectionType;
use App\Modules\CommercialProposals\Domain\Enums\VersionState;
use App\Modules\CommercialProposals\Domain\ValueObjects\Money;
use App\Modules\CommercialProposals\Domain\ValueObjects\Quantity;
use App\Modules\CommercialProposals\Models\Proposal;
use App\Modules\CommercialProposals\Models\ProposalVersion;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Явное обновление snapshot-строк черновика из справочника (план §7.3):
 * меняет только связанные строки текущего draft, пересчитывает суммы
 * и итоги; issued и superseded версии неизменяемы и сюда не попадают.
 */
final class RefreshDraftItemsFromCatalogAction
{
    public function __construct(
        private readonly CatalogItemLookupPort $catalogItems,
    ) {}

    /**
     * @return array{updated: int}
     */
    public function execute(User $actor, Proposal $proposal): array
    {
        return DB::transaction(function () use ($actor, $proposal) {
            /** @var ProposalVersion $version */
            $version = $proposal->draftVersion()->lockForUpdate()->firstOrFail();

            if ($version->state !== VersionState::Draft) {
                throw new ConflictHttpException('Обновлять можно только черновик.');
            }

            $items = $version->items()->whereNotNull('catalog_item_id')->get();

            if ($items->isEmpty()) {
                return ['updated' => 0];
            }

            $catalog = $this->catalogItems->findByIds(array_values($items->pluck('catalog_item_id')->map(intval(...))->all()));

            $updated = 0;

            foreach ($items as $item) {
                $snapshot = $catalog[$item->catalog_item_id] ?? null;

                if ($snapshot === null) {
                    continue;
                }

                $changed = $item->name !== $snapshot->name
                    || (string) $item->unit !== (string) $snapshot->unit
                    || $item->unit_price !== $snapshot->price;

                if (! $changed) {
                    continue;
                }

                $price = Money::fromDecimalString($snapshot->price);
                $quantityMin = Quantity::fromString($item->quantity_min);
                $quantityMax = Quantity::fromString($item->quantity_max);

                $item->fill([
                    'name' => $snapshot->name,
                    'unit' => $snapshot->unit,
                    'unit_price' => $price->toDecimalString(),
                    'amount_min' => Money::lineAmount($quantityMin->toDecimalString(), $price)->toDecimalString(),
                    'amount_max' => Money::lineAmount($quantityMax->toDecimalString(), $price)->toDecimalString(),
                ]);
                $item->save();
                $updated++;
            }

            if ($updated > 0) {
                $refreshedByCatalogId = $items->keyBy('catalog_item_id');

                // Payload items-секций — редактируемый снимок блока: без
                // синхронизации после перезагрузки редактор показал бы
                // прежнюю цену, хотя snapshot-строки уже обновлены.
                foreach ($version->sections()->where('type', SectionType::Items)->get() as $section) {
                    $payload = $section->payload;
                    $rows = $payload['items'] ?? null;

                    if (! is_array($rows)) {
                        continue;
                    }

                    $dirty = false;

                    foreach ($rows as $i => $row) {
                        $snapshot = $refreshedByCatalogId->get($row['catalog_item_id'] ?? null);

                        if ($snapshot === null) {
                            continue;
                        }

                        if (($row['name'] ?? null) === $snapshot->name
                            && ($row['unit'] ?? null) === (string) $snapshot->unit
                            && (string) ($row['unit_price'] ?? '') === (string) $snapshot->unit_price) {
                            continue;
                        }

                        $row['name'] = $snapshot->name;
                        $row['unit'] = $snapshot->unit;
                        $row['unit_price'] = $snapshot->unit_price;
                        $rows[$i] = $row;
                        $dirty = true;
                    }

                    if ($dirty) {
                        $payload['items'] = $rows;
                        $section->fill(['payload' => $payload])->save();
                    }
                }

                // Итоги: legacy-строки + строки только включённых items-секций
                // (выключенный блок в документ не идёт и в сумму не входит).
                $enabledSectionIds = $version->sections()->where('enabled', true)->pluck('id');
                $countedItems = $version->items()
                    ->where(fn ($query) => $query->whereNull('section_id')->orWhereIn('section_id', $enabledSectionIds))
                    ->get();

                $totalsMin = Money::zero();
                $totalsMax = Money::zero();

                foreach ($countedItems as $item) {
                    $totalsMin = $totalsMin->add(Money::fromDecimalString($item->amount_min));
                    $totalsMax = $totalsMax->add(Money::fromDecimalString($item->amount_max));
                }

                $version->fill([
                    'total_min' => $totalsMin->toDecimalString(),
                    'total_max' => $totalsMax->toDecimalString(),
                    'lock_version' => $version->lock_version + 1,
                ]);
                $version->save();
            }

            Audit::record($actor, 'proposal.catalog-refreshed', $proposal, [], [
                'version' => $version->version,
                'updated' => $updated,
            ]);

            return ['updated' => $updated];
        });
    }
}

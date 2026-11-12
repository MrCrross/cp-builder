<?php

namespace App\Modules\CommercialProposals\Application\Actions;

use App\Modules\CommercialProposals\Domain\Money;
use App\Modules\CommercialProposals\Domain\Quantity;
use App\Modules\CommercialProposals\Domain\SectionPayloadValidator;
use App\Modules\CommercialProposals\Domain\SectionType;
use App\Modules\CommercialProposals\Domain\VersionState;
use App\Modules\CommercialProposals\Infrastructure\Persistence\Proposal;
use App\Modules\CommercialProposals\Infrastructure\Persistence\ProposalVersion;
use App\Modules\CompanySettings\Application\Contracts\BrandingSnapshotPort;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Сохранение черновика (план §6.3): lock_version против lost-update (409),
 * пересчёт денег доменными VO (half-up на строку, §7.2), замена детей
 * в одной транзакции. Frontend не присылает totals — они доверенные
 * только отсюда. Branding snapshot обновляется до текущего: preview
 * черновика видит актуальный брендинг, фиксация — при выпуске.
 *
 * @phpstan-type ItemInput array{name: string, description?: ?string, unit?: ?string, quantity_min: string, quantity_max: string, unit_price: string, catalog_item_id?: ?int}
 * @phpstan-type SectionInput array{type: string, enabled?: bool, payload?: array<string, mixed>}
 */
final class SaveDraftAction
{
    /**
     * @param  array{title: string, subtitle?: ?string, client_name?: ?string, client_contact?: ?string, manager_name?: ?string, manager_email?: ?string, manager_phone?: ?string, note?: ?string, items?: list<ItemInput>, sections?: list<SectionInput>, lock_version: int}  $data
     */
    public function execute(Proposal $proposal, array $data): ProposalVersion
    {
        return DB::transaction(function () use ($proposal, $data) {
            /** @var ProposalVersion $version */
            $version = $proposal->draftVersion()->lockForUpdate()->firstOrFail();

            if ($version->lock_version !== (int) $data['lock_version']) {
                throw new ConflictHttpException('Черновик изменён другим пользователем. Обновите страницу.');
            }

            if ($version->state !== VersionState::Draft) {
                throw new ConflictHttpException('Выпущенные версии не редактируются.');
            }

            $items = $this->buildItems($data['items'] ?? []);
            $totals = $this->totals($items);

            $sections = [];
            foreach (($data['sections'] ?? []) as $position => $section) {
                $type = SectionType::tryFrom((string) $section['type'])
                    ?? throw new UnprocessableEntityHttpException("Неизвестный тип секции: {$section['type']}");

                $sections[] = [
                    'position' => $position + 1,
                    'type' => $type->value,
                    'enabled' => (bool) ($section['enabled'] ?? true),
                    'payload' => SectionPayloadValidator::validate($type, $section['payload'] ?? []),
                ];
            }

            $version->fill([
                'title' => $data['title'],
                'subtitle' => $data['subtitle'] ?? null,
                'client_name' => $data['client_name'] ?? null,
                'client_contact' => $data['client_contact'] ?? null,
                'manager_name' => $data['manager_name'] ?? null,
                'manager_email' => $data['manager_email'] ?? null,
                'manager_phone' => $data['manager_phone'] ?? null,
                'note' => $data['note'] ?? null,
                'total_min' => $totals['min']->toDecimalString(),
                'total_max' => $totals['max']->toDecimalString(),
                'branding_snapshot' => app(BrandingSnapshotPort::class)->current()->toArray(),
                'lock_version' => $version->lock_version + 1,
            ]);
            $version->save();

            $version->items()->delete();
            $version->items()->createMany($items);

            $version->sections()->delete();
            $version->sections()->createMany($sections);

            return $version->refresh();
        });
    }

    /**
     * @param  list<ItemInput>  $input
     * @return list<array<string, mixed>>
     */
    private function buildItems(array $input): array
    {
        if (count($input) > 500) {
            throw new UnprocessableEntityHttpException('Слишком много позиций (максимум 500).');
        }

        $items = [];

        foreach ($input as $position => $row) {
            $quantityMin = Quantity::fromString((string) $row['quantity_min']);
            $quantityMax = Quantity::fromString((string) $row['quantity_max']);

            if ($quantityMin->lessThan($quantityMax) === false && $quantityMin->compare($quantityMax) !== 0) {
                throw new UnprocessableEntityHttpException('Минимальное количество не может превышать максимальное.');
            }

            $price = Money::fromDecimalString((string) $row['unit_price']);
            $amountMin = Money::lineAmount($quantityMin->toDecimalString(), $price);
            $amountMax = Money::lineAmount($quantityMax->toDecimalString(), $price);

            $name = trim((string) $row['name']);
            if ($name === '' || mb_strlen($name) > 500) {
                throw new UnprocessableEntityHttpException('Название позиции обязательно (до 500 символов).');
            }

            $items[] = [
                'position' => $position + 1,
                'catalog_item_id' => $row['catalog_item_id'] ?? null,
                'name' => $name,
                'description' => $row['description'] ?? null,
                'unit' => $row['unit'] ?? null,
                'quantity_min' => $quantityMin->toDecimalString(),
                'quantity_max' => $quantityMax->toDecimalString(),
                'unit_price' => $price->toDecimalString(),
                'amount_min' => $amountMin->toDecimalString(),
                'amount_max' => $amountMax->toDecimalString(),
            ];
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{min: Money, max: Money}
     */
    private function totals(array $items): array
    {
        $min = Money::zero();
        $max = Money::zero();

        foreach ($items as $item) {
            $min = $min->add(Money::fromDecimalString($item['amount_min']));
            $max = $max->add(Money::fromDecimalString($item['amount_max']));
        }

        return ['min' => $min, 'max' => $max];
    }
}

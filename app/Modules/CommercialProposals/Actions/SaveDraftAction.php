<?php

namespace App\Modules\CommercialProposals\Actions;

use App\Modules\CommercialProposals\Domain\Enums\SectionType;
use App\Modules\CommercialProposals\Domain\Enums\VersionState;
use App\Modules\CommercialProposals\Domain\Services\ItemRowsBuilder;
use App\Modules\CommercialProposals\Domain\Services\SectionPayloadValidator;
use App\Modules\CommercialProposals\Domain\ValueObjects\Money;
use App\Modules\CommercialProposals\Models\Proposal;
use App\Modules\CommercialProposals\Models\ProposalVersion;
use App\Modules\CompanySettings\Contracts\BrandingSnapshotPort;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Сохранение черновика (план §6.3): lock_version против lost-update (409),
 * пересчёт денег доменными VO (half-up на строку, §7.2), замена детей
 * в одной транзакции. Frontend не присылает totals — они доверенные
 * только отсюда. Branding snapshot обновляется до текущего: preview
 * черновика видит актуальный брендинг, фиксация — при выпуске.
 *
 * Позиции — блок типа items: строки читаются из payload таких секций
 * (в порядке следования блоков) и пишутся в snapshot proposal_items
 * с привязкой section_id. Топ-уровневый items оставлен для обратной
 * совместимости: такие строки получают section_id NULL и рендерятся
 * прежней шапочной таблицей.
 *
 * @phpstan-type ItemInput array{name: string, description?: ?string, unit?: ?string, quantity_min: string, quantity_max: string, unit_price: string, catalog_item_id?: ?int}
 * @phpstan-type SectionInput array{type: string, enabled?: bool, payload?: array<string, mixed>}
 *
 * @phpstan-import-type ItemRow from ItemRowsBuilder
 */
final class SaveDraftAction
{
    public function __construct(
        private readonly ItemRowsBuilder $itemRows = new ItemRowsBuilder,
    ) {}

    /**
     * @param  array{title: string, subtitle?: ?string, client_name?: ?string, client_contact?: ?string, manager_name?: ?string, manager_email?: ?string, manager_phone?: ?string, note?: ?string, project_id?: ?int, items?: list<ItemInput>, sections?: list<SectionInput>, lock_version: int}  $data
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

            if (array_key_exists('project_id', $data)) {
                $proposal->fill(['project_id' => $data['project_id']])->save();
            }

            $legacyRows = $this->itemRows->build($data['items'] ?? []);

            $sections = [];
            /** @var array<int, list<ItemRow>> $itemRowsBySection индекс секции → строки блока items */
            $itemRowsBySection = [];

            foreach (($data['sections'] ?? []) as $position => $section) {
                $type = SectionType::tryFrom((string) $section['type'])
                    ?? throw new UnprocessableEntityHttpException("Неизвестный тип секции: {$section['type']}");

                try {
                    $payload = SectionPayloadValidator::validate($type, $section['payload'] ?? []);
                } catch (ValidationException $e) {
                    // Ошибки payload привязываем к конкретной секции, чтобы
                    // форма показала их у нужного поля, а не общим списком.
                    $prefixed = [];
                    foreach ($e->errors() as $key => $messages) {
                        $prefixed["sections.{$position}.payload.{$key}"] = $messages;
                    }

                    throw ValidationException::withMessages($prefixed);
                }

                $sections[] = [
                    'position' => $position + 1,
                    'type' => $type->value,
                    'enabled' => (bool) ($section['enabled'] ?? true),
                    'payload' => $payload,
                ];

                if ($type === SectionType::Items) {
                    $itemRowsBySection[$position] = $this->itemRows->build($payload['items'] ?? []);
                }
            }

            $itemCount = count($legacyRows) + array_sum(array_map(count(...), $itemRowsBySection));
            if ($itemCount > 500) {
                throw new UnprocessableEntityHttpException('Слишком много позиций (максимум 500).');
            }

            // В итоги входят legacy-строки и строки только включённых блоков
            // позиций: выключенный блок не показывается в документе и в сумму
            // не идёт.
            $countedRows = $legacyRows;
            foreach ($itemRowsBySection as $sectionIndex => $rows) {
                if ($sections[$sectionIndex]['enabled']) {
                    $countedRows = [...$countedRows, ...$rows];
                }
            }

            $totals = $this->totals($countedRows);

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

            // Дети пересоздаются целиком: сначала строки (FK на секции),
            // потом секции, затем новые секции и строки с привязкой.
            $version->items()->delete();
            $version->sections()->delete();
            $savedSections = $version->sections()->createMany($sections);

            $created = [];
            $position = 1;
            foreach ($legacyRows as $row) {
                $created[] = [...$row, 'position' => $position++];
            }
            foreach ($itemRowsBySection as $sectionIndex => $rows) {
                $sectionId = (int) $savedSections[$sectionIndex]->id;
                foreach ($rows as $row) {
                    $created[] = [...$row, 'section_id' => $sectionId, 'position' => $position++];
                }
            }
            $version->items()->createMany($created);

            return $version->refresh();
        });
    }

    /**
     * @param  list<ItemRow>  $items
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

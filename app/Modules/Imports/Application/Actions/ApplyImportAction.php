<?php

namespace App\Modules\Imports\Application\Actions;

use App\Models\User;
use App\Modules\CommercialProposals\Domain\Money;
use App\Modules\Imports\Application\Contracts\CatalogImportPort;
use App\Modules\Imports\Application\Contracts\ImportedCatalogItem;
use App\Modules\Imports\Domain\ImportJobStatus;
use App\Modules\Imports\Domain\ImportProfile;
use App\Modules\Imports\Domain\ImportRowAction;
use App\Modules\Imports\Domain\ParsedItem;
use App\Modules\Imports\Infrastructure\Persistence\ImportJob;
use App\Modules\Imports\Infrastructure\Persistence\ImportRow;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Применение подтверждённого staging к каталогу (план §13.1 п.6):
 * батчи по 1000 в транзакциях, upsert по unique (source, external_id)
 * через CatalogImportPort — повторное применение не создаёт дублей,
 * КП автоматически не меняются (snapshot, план §7.3).
 */
final class ApplyImportAction
{
    private const int BATCH_SIZE = 1000;

    public function __construct(
        private readonly CatalogImportPort $catalogImport,
    ) {}

    public function execute(int $jobId): void
    {
        $job = ImportJob::query()->findOrFail($jobId);

        if (ImportJobStatus::from($job->status) !== ImportJobStatus::Processing) {
            return; // защита от повторного запуска job'а
        }

        $source = ImportProfile::from($job->profile)->value;
        $created = 0;
        $updated = 0;
        $deactivated = 0;
        $errors = 0;
        $processed = 0;

        ImportRow::query()
            ->where('job_id', $job->id)
            ->whereIn('action', [
                ImportRowAction::Add->value,
                ImportRowAction::Change->value,
                ImportRowAction::Delete->value,
            ])
            ->chunkById(self::BATCH_SIZE, function ($rows) use (&$created, &$updated, &$deactivated, &$errors, &$processed, $job, $source): void {
                DB::transaction(function () use ($rows, &$created, &$updated, &$deactivated, &$errors, &$processed, $source): void {
                    foreach ($rows as $row) {
                        $action = ImportRowAction::from($row->action);

                        if ($action === ImportRowAction::Delete) {
                            $processed++;
                            if ($this->catalogImport->deactivate($source, $row->external_id)) {
                                $deactivated++;
                            }

                            continue;
                        }

                        $item = $this->toImportedItem($source, $row);

                        if ($item === null) {
                            $errors++; // валидация parse была, каталог мог не принять значение

                            continue;
                        }

                        $processed++;
                        if ($this->catalogImport->upsert($source, $row->external_id, $item)) {
                            $created++;
                        } else {
                            $updated++;
                        }
                    }
                });

                $job->update([
                    'rows_processed' => $processed,
                    'applied_created' => $created,
                    'applied_updated' => $updated,
                    'applied_deactivated' => $deactivated,
                ]);
            });

        $job->update([
            'status' => $errors > 0
                ? ImportJobStatus::CompletedWithErrors->value
                : ImportJobStatus::Completed->value,
            'rows_processed' => $processed,
            'applied_created' => $created,
            'applied_updated' => $updated,
            'applied_deactivated' => $deactivated,
            'rows_error' => $job->rows_error + $errors,
            'applied_at' => now(),
        ]);

        Audit::record(
            User::query()->find($job->user_id),
            'import.applied',
            $job,
            [],
            [
                'created' => $created,
                'updated' => $updated,
                'deactivated' => $deactivated,
                'errors' => $errors,
            ],
        );
    }

    private function toImportedItem(string $source, ImportRow $row): ?ImportedCatalogItem
    {
        $item = ParsedItem::fromPayload($row->payload);

        if ($item->name === null || $item->name === '' || $item->price === null || $item->price === '') {
            return null;
        }

        try {
            $price = Money::fromDecimalString($item->price)->toDecimalString();
        } catch (\InvalidArgumentException) {
            return null;
        }

        $currency = $item->currency ?? 'RUB';

        if ($currency !== 'RUB') {
            return null;
        }

        return new ImportedCatalogItem(
            name: $item->name,
            description: $item->description,
            unit: $item->unit,
            price: $price,
            currency: $currency,
            properties: $item->properties,
            image: $item->image,
            sourceUrl: $item->sourceUrl,
        );
    }
}

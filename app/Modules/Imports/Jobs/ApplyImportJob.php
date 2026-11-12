<?php

namespace App\Modules\Imports\Jobs;

use App\Modules\Imports\Application\Actions\ApplyImportAction;
use App\Modules\Imports\Domain\ImportJobStatus;
use App\Modules\Imports\Infrastructure\Persistence\ImportJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Фоновое применение staging к каталогу: батчи внутри экшена, прогресс
 * в колонках job'а; исчерпание tries → failed() помечает job failed.
 */
final class ApplyImportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public function __construct(
        public readonly int $importJobId,
    ) {}

    public function handle(ApplyImportAction $action): void
    {
        $action->execute($this->importJobId);
    }

    public function failed(?Throwable $exception): void
    {
        try {
            ImportJob::query()
                ->where('id', $this->importJobId)
                ->where('status', ImportJobStatus::Processing->value)
                ->update([
                    'status' => ImportJobStatus::Failed->value,
                    'error_message' => $exception?->getMessage(),
                ]);
        } catch (Throwable) {
            // БД недоступна — не маскируем исходную ошибку job'а.
        }
    }
}

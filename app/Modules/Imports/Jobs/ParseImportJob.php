<?php

namespace App\Modules\Imports\Jobs;

use App\Modules\Imports\Application\Actions\ParseImportFileAction;
use App\Modules\Imports\Domain\ImportJobStatus;
use App\Modules\Imports\Domain\ImportParseException;
use App\Modules\Imports\Infrastructure\Persistence\ImportJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Фоновый разбор файлов job'а. Permanent parse/validation errors не
 * ретраятся (план §13.1 п.7); прочие сбои — bounded retry через tries.
 */
final class ParseImportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public function __construct(
        public readonly int $importJobId,
    ) {}

    public function handle(ParseImportFileAction $action): void
    {
        try {
            $action->execute($this->importJobId);
        } catch (ImportParseException $exception) {
            // Статус/сообщение job уже проставлены экшеном; retry бессмысленен.
            $this->fail($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        try {
            ImportJob::query()
                ->where('id', $this->importJobId)
                ->whereIn('status', [
                    ImportJobStatus::Uploaded->value,
                    ImportJobStatus::Validating->value,
                ])
                ->update([
                    'status' => ImportJobStatus::Failed->value,
                    'error_message' => $exception?->getMessage(),
                ]);
        } catch (Throwable) {
            // БД недоступна — не маскируем исходную ошибку job'а.
        }
    }
}

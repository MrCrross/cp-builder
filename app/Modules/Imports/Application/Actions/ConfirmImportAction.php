<?php

namespace App\Modules\Imports\Application\Actions;

use App\Models\User;
use App\Modules\Imports\Domain\ImportJobStatus;
use App\Modules\Imports\Infrastructure\Persistence\ImportJob;
use App\Modules\Imports\Jobs\ApplyImportJob;
use App\Support\Audit\Audit;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Явное подтверждение apply после preview (план §13.1 п.5): только из
 * awaiting_confirmation; ставит фоновый ApplyImportJob.
 */
final class ConfirmImportAction
{
    public function execute(User $actor, ImportJob $job): ImportJob
    {
        if (ImportJobStatus::from($job->status) !== ImportJobStatus::AwaitingConfirmation) {
            throw new ConflictHttpException('Импорт не ожидает подтверждения.');
        }

        $job->update(['status' => ImportJobStatus::Processing->value]);

        Audit::record($actor, 'import.confirmed', $job, [], ['rows' => $job->rows_total]);

        ApplyImportJob::dispatch($job->id)->onQueue('imports');

        return $job;
    }
}

<?php

namespace App\Modules\Imports\Application\Actions;

use App\Models\User;
use App\Modules\Imports\Domain\ImportJobStatus;
use App\Modules\Imports\Infrastructure\Persistence\ImportJob;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Отмена job'а до подтверждения: каталог не тронут, quarantine-файлы
 * удаляются, staging остаётся для разбора (почему отменено).
 */
final class CancelImportAction
{
    public function execute(User $actor, ImportJob $job): ImportJob
    {
        $status = ImportJobStatus::from($job->status);

        $cancellable = [
            ImportJobStatus::Uploaded,
            ImportJobStatus::Validating,
            ImportJobStatus::AwaitingConfirmation,
        ];

        if (! in_array($status, $cancellable, true)) {
            throw new ConflictHttpException('Импорт в текущем состоянии отменить нельзя.');
        }

        foreach ($job->files()->cursor() as $file) {
            Storage::disk('local')->delete($file->quarantine_path);
        }

        $job->update(['status' => ImportJobStatus::Cancelled->value]);

        Audit::record($actor, 'import.cancelled', $job, [], ['profile' => $job->profile]);

        return $job;
    }
}

<?php

namespace App\Modules\Imports\Actions;

use App\Models\User;
use App\Modules\Imports\Domain\Enums\ImportJobStatus;
use App\Modules\Imports\Domain\Enums\ImportProfile;
use App\Modules\Imports\Jobs\ParseImportJob;
use App\Modules\Imports\Models\ImportFile;
use App\Modules\Imports\Models\ImportJob;
use App\Support\Audit\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Upload файлов в private-quarantine и постановка job'а на разбор.
 * Один активный job на тот же file+mapping (план §13.1): повторная
 * загрузка того же hash при незавершённом job'е отклоняется 409.
 */
final class UploadImportFilesAction
{
    /**
     * @param  array<int, UploadedFile>  $files
     *
     * @throws ConflictHttpException активный job с тем же файлом
     */
    public function execute(User $actor, ImportProfile $profile, array $files): ImportJob
    {
        /** @var array<int, array{file: UploadedFile, path: string, hash: string}> $prepared */
        $prepared = array_map(function (UploadedFile $file): array {
            $path = $file->getRealPath();

            if ($path === false) {
                throw new InvalidArgumentException('Не удалось прочитать загруженный файл.');
            }

            return ['file' => $file, 'path' => $path, 'hash' => hash_file('sha256', $path)];
        }, $files);

        $hashes = array_map(fn (array $item): string => $item['hash'], $prepared);

        $activeStatuses = [
            ImportJobStatus::Uploaded->value,
            ImportJobStatus::Validating->value,
            ImportJobStatus::AwaitingConfirmation->value,
            ImportJobStatus::Processing->value,
        ];

        $duplicate = ImportJob::query()
            ->whereIn('status', $activeStatuses)
            ->where('profile', $profile->value)
            ->whereHas('files', fn ($query) => $query->whereIn('sha256', $hashes))
            ->exists();

        if ($duplicate) {
            throw new ConflictHttpException('Файл с таким содержимым уже обрабатывается в другом импорте.');
        }

        $job = ImportJob::query()->create([
            'user_id' => $actor->id,
            'profile' => $profile->value,
            'status' => ImportJobStatus::Uploaded->value,
        ]);

        $totalSize = 0;

        foreach ($prepared as $index => $item) {
            $file = $item['file'];
            $size = (int) $file->getSize();
            $totalSize += $size;

            $quarantinePath = "imports/quarantine/{$job->id}/".hash('sha256', $item['hash'].$index).'.xml';

            $stream = fopen($item['path'], 'rb');

            if ($stream === false) {
                throw new InvalidArgumentException("Не удалось прочитать загруженный файл «{$file->getClientOriginalName()}».");
            }

            Storage::disk('local')->put($quarantinePath, $stream);
            fclose($stream);

            ImportFile::query()->create([
                'job_id' => $job->id,
                'original_name' => (string) $file->getClientOriginalName(),
                'sha256' => $item['hash'],
                'size_bytes' => $size,
                'quarantine_path' => $quarantinePath,
            ]);
        }

        Audit::record($actor, 'import.uploaded', $job, [], [
            'profile' => $profile->value,
            'files' => count($files),
            'size' => $totalSize,
        ]);

        ParseImportJob::dispatch($job->id)->onQueue('imports');

        return $job;
    }
}

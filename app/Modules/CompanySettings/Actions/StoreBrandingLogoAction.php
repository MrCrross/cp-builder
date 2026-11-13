<?php

namespace App\Modules\CompanySettings\Actions;

use App\Models\User;
use App\Modules\CompanySettings\Models\BrandingAsset;
use App\Modules\CompanySettings\Support\RasterImageProcessor;
use App\Support\Audit\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Замена логотипа организации (§9.4, §10.1): строгая валидация, повторное
 * кодирование, случайное имя без оригинального filename. Старый файл
 * удаляется после commit: выпущенные документы хранят собственные копии
 * (snapshot, §7.3), поэтому замена бренда их не трогает.
 */
final class StoreBrandingLogoAction
{
    public function execute(User $actor, string $kind, UploadedFile $file): BrandingAsset
    {
        $processed = RasterImageProcessor::process(
            (string) file_get_contents($file->getRealPath()),
            RasterImageProcessor::MAX_BYTES_LOGO,
        );

        $path = 'branding/'.$kind.'-'.Str::lower(Str::random(32)).'.'.$processed['extension'];

        $oldPath = null;

        try {
            Storage::disk('local')->put($path, $processed['binary']);

            $asset = DB::transaction(function () use ($kind, $path, $processed, $actor, &$oldPath) {
                $previous = BrandingAsset::query()->where('kind', $kind)->first();
                $oldPath = $previous?->path;

                // Сначала освобождаем unique(kind), затем создаём новую запись:
                // при любом сбое транзакция откатит оба изменения.
                $previous?->delete();

                $asset = BrandingAsset::query()->create([
                    'kind' => $kind,
                    'disk' => 'local',
                    'path' => $path,
                    'mime' => $processed['mime'],
                    'bytes' => strlen($processed['binary']),
                    'width' => $processed['width'],
                    'height' => $processed['height'],
                    'checksum' => hash('sha256', $processed['binary']),
                    'created_at' => now(),
                ]);

                Audit::record($actor, 'branding.logo-uploaded', null, [], [
                    'kind' => $kind,
                    'mime' => $processed['mime'],
                    'bytes' => strlen($processed['binary']),
                    'width' => $processed['width'],
                    'height' => $processed['height'],
                ]);

                return $asset;
            });
        } catch (\Throwable $e) {
            // Компенсация: файл записан, но транзакция не состоялась.
            Storage::disk('local')->delete($path);

            throw $e;
        }

        DB::afterCommit(function () use ($oldPath) {
            if ($oldPath !== null) {
                Storage::disk('local')->delete($oldPath);
            }
        });

        return $asset;
    }
}

<?php

namespace App\Modules\CompanySettings\Application\Actions;

use App\Models\User;
use App\Modules\CompanySettings\Infrastructure\Persistence\BrandingAsset;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Удаление логотипа организации. Подтверждение — на стороне UI;
 * файл удаляется после commit (§6.3).
 */
final class DeleteBrandingLogoAction
{
    public function execute(User $actor, string $kind): void
    {
        $asset = BrandingAsset::query()->where('kind', $kind)->first();

        if ($asset === null) {
            throw new NotFoundHttpException;
        }

        $path = $asset->path;

        DB::transaction(function () use ($asset, $actor, $kind) {
            $asset->delete();

            Audit::record($actor, 'branding.logo-deleted', null, [], ['kind' => $kind]);
        });

        DB::afterCommit(fn () => Storage::disk('local')->delete($path));
    }
}

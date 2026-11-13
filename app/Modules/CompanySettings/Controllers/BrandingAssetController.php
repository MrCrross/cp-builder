<?php

namespace App\Modules\CompanySettings\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CompanySettings\Actions\DeleteBrandingLogoAction;
use App\Modules\CompanySettings\Actions\StoreBrandingLogoAction;
use App\Modules\CompanySettings\Models\BrandingAsset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BrandingAssetController extends Controller
{
    /**
     * Загрузка/замена логотипа. Строгая валидация — в FormRequest нельзя
     * выразить content-based проверки, поэтому они в RasterImageProcessor;
     * здесь только базовые пределы запроса.
     */
    public function store(Request $request, string $kind, StoreBrandingLogoAction $action): RedirectResponse
    {
        abort_unless(in_array($kind, [BrandingAsset::KIND_LARGE, BrandingAsset::KIND_SMALL], true), 404);

        Gate::authorize('uploadLogo', BrandingAsset::class);

        $request->validate([
            'logo' => ['required', 'file', 'max:2048'],
        ]);

        $action->execute($request->user(), $kind, $request->file('logo'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Логотип обновлён.')]);

        return back();
    }

    public function destroy(Request $request, string $kind, DeleteBrandingLogoAction $action): RedirectResponse
    {
        abort_unless(in_array($kind, [BrandingAsset::KIND_LARGE, BrandingAsset::KIND_SMALL], true), 404);

        Gate::authorize('uploadLogo', BrandingAsset::class);

        $action->execute($request->user(), $kind);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Логотип удалён.')]);

        return back();
    }

    /**
     * Отдача файла логотипа для внутреннего preview (§9.4: приватные
     * originals недоступны через Caddy напрямую).
     */
    public function show(string $kind): StreamedResponse
    {
        abort_unless(in_array($kind, [BrandingAsset::KIND_LARGE, BrandingAsset::KIND_SMALL], true), 404);

        Gate::authorize('viewLogo', BrandingAsset::class);

        $asset = BrandingAsset::query()->where('kind', $kind)->firstOrFail();

        return Storage::disk($asset->disk)
            ->response($asset->path, null, [
                'Content-Type' => $asset->mime,
                'Cache-Control' => 'private, max-age=3600',
            ]);
    }
}

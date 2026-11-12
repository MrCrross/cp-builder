<?php

namespace App\Modules\CompanySettings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CompanySettings\Application\Actions\UpdateOrganizationSettingsAction;
use App\Modules\CompanySettings\Http\Requests\UpdateOrganizationSettingsRequest;
use App\Modules\CompanySettings\Infrastructure\Persistence\BrandingAsset;
use App\Modules\CompanySettings\Infrastructure\Persistence\OrganizationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationSettingsController extends Controller
{
    public function edit(): Response
    {
        Gate::authorize('viewAny', OrganizationSettings::class);

        $settings = OrganizationSettings::current();

        return Inertia::render('settings-branding/BrandingSettingsPage', [
            'organization' => [
                'name' => $settings->name,
                'legal_name' => $settings->legal_name,
                'city' => $settings->city,
                'website' => $settings->website,
                'slogan' => $settings->slogan,
            ],
            'logos' => [
                'large' => $this->logoPayload('large_logo'),
                'small' => $this->logoPayload('small_logo'),
            ],
        ]);
    }

    public function update(UpdateOrganizationSettingsRequest $request, UpdateOrganizationSettingsAction $action): RedirectResponse
    {
        /** @var array{name: string, legal_name?: ?string, city?: ?string, website?: ?string, slogan?: ?string} $validated */
        $validated = $request->validated();

        $action->execute($request->user(), $validated);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Настройки организации сохранены.')]);

        return back();
    }

    /**
     * @return array{exists: bool, url: ?string, width: ?int, height: ?int, bytes: ?int}
     */
    private function logoPayload(string $kind): array
    {
        $asset = BrandingAsset::query()
            ->where('kind', $kind)
            ->first();

        return [
            'exists' => $asset !== null,
            'url' => $asset === null ? null : route('settings.branding.logo', ['kind' => $kind]),
            'width' => $asset?->width,
            'height' => $asset?->height,
            'bytes' => $asset?->bytes,
        ];
    }
}

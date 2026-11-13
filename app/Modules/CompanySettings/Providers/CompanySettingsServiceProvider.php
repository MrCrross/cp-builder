<?php

namespace App\Modules\CompanySettings\Providers;

use App\Modules\CompanySettings\Contracts\BrandingSnapshotPort;
use App\Modules\CompanySettings\Models\BrandingAsset;
use App\Modules\CompanySettings\Models\OrganizationSettings;
use App\Modules\CompanySettings\Policies\BrandingPolicy;
use App\Modules\CompanySettings\Support\DatabaseBrandingSnapshotProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class CompanySettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BrandingSnapshotPort::class, DatabaseBrandingSnapshotProvider::class);
    }

    public function boot(): void
    {
        Gate::policy(OrganizationSettings::class, BrandingPolicy::class);
        Gate::policy(BrandingAsset::class, BrandingPolicy::class);
    }
}

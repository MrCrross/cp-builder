<?php

namespace App\Modules\CompanySettings;

use App\Modules\CompanySettings\Application\Contracts\BrandingSnapshotPort;
use App\Modules\CompanySettings\Authorization\BrandingPolicy;
use App\Modules\CompanySettings\Infrastructure\DatabaseBrandingSnapshotProvider;
use App\Modules\CompanySettings\Infrastructure\Persistence\BrandingAsset;
use App\Modules\CompanySettings\Infrastructure\Persistence\OrganizationSettings;
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

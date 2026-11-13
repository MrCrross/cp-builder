<?php

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\Catalog\Policies\CatalogCategoryPolicy;
use App\Modules\Catalog\Policies\CatalogItemPolicy;
use App\Modules\Catalog\Support\CatalogItemImporter;
use App\Modules\Catalog\Support\CatalogItemLookup;
use App\Modules\CommercialProposals\Contracts\CatalogItemLookupPort;
use App\Modules\Imports\Contracts\CatalogImportPort;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            CatalogItemLookupPort::class,
            CatalogItemLookup::class,
        );

        $this->app->bind(
            CatalogImportPort::class,
            CatalogItemImporter::class,
        );
    }

    public function boot(): void
    {
        Gate::policy(CatalogCategory::class, CatalogCategoryPolicy::class);
        Gate::policy(CatalogItem::class, CatalogItemPolicy::class);
    }
}

<?php

namespace App\Modules\Catalog;

use App\Modules\Catalog\Authorization\CatalogCategoryPolicy;
use App\Modules\Catalog\Authorization\CatalogItemPolicy;
use App\Modules\Catalog\Infrastructure\CatalogItemImporter;
use App\Modules\Catalog\Infrastructure\Persistence\CatalogCategory;
use App\Modules\Catalog\Infrastructure\Persistence\CatalogItem;
use App\Modules\CommercialProposals\Application\Contracts\CatalogItemLookupPort;
use App\Modules\Imports\Application\Contracts\CatalogImportPort;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            CatalogItemLookupPort::class,
            Infrastructure\CatalogItemLookup::class,
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

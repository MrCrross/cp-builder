<?php

namespace App\Modules\Imports;

use App\Modules\Imports\Authorization\ImportJobPolicy;
use App\Modules\Imports\Infrastructure\Persistence\ImportJob;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Модуль Imports (план §13.1): потоковый разбор XML/YML в staging,
 * preview add/change/skip, подтверждаемый apply в каталог через порт
 * CatalogImportPort (реализация CatalogItemImporter живёт в модуле
 * Catalog и регистрируется там — cross-module Eloquent запрещён §6.1).
 */
class ImportsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(ImportJob::class, ImportJobPolicy::class);
    }
}

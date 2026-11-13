<?php

use App\Modules\AuditLog\Providers\AuditLogServiceProvider;
use App\Modules\Catalog\Providers\CatalogServiceProvider;
use App\Modules\CommercialProposals\Providers\CommercialProposalsServiceProvider;
use App\Modules\CompanySettings\Providers\CompanySettingsServiceProvider;
use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Modules\Imports\Providers\ImportsServiceProvider;
use App\Modules\Projects\Providers\ProjectsServiceProvider;
use App\Modules\Statistics\Providers\StatisticsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,

    // Domain modules (план §6.2): bindings/listeners регистрируются здесь.
    IdentityServiceProvider::class,
    CompanySettingsServiceProvider::class,
    ProjectsServiceProvider::class,
    CatalogServiceProvider::class,
    CommercialProposalsServiceProvider::class,
    StatisticsServiceProvider::class,
    ImportsServiceProvider::class,
    AuditLogServiceProvider::class,
];

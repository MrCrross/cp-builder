<?php

use App\Modules\AuditLog\AuditLogServiceProvider;
use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\CommercialProposals\CommercialProposalsServiceProvider;
use App\Modules\CompanySettings\CompanySettingsServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Projects\ProjectsServiceProvider;
use App\Modules\Statistics\StatisticsServiceProvider;
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
    AuditLogServiceProvider::class,
];

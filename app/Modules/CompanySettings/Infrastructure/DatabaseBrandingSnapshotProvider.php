<?php

namespace App\Modules\CompanySettings\Infrastructure;

use App\Modules\CompanySettings\Application\Contracts\BrandingSnapshotPort;
use App\Modules\CompanySettings\Application\Data\BrandingSnapshotData;
use App\Modules\CompanySettings\Infrastructure\Persistence\OrganizationSettings;

final class DatabaseBrandingSnapshotProvider implements BrandingSnapshotPort
{
    public function current(): BrandingSnapshotData
    {
        return BrandingSnapshotData::fromSettings(OrganizationSettings::current());
    }
}

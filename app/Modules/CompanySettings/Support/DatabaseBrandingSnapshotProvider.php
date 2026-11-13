<?php

namespace App\Modules\CompanySettings\Support;

use App\Modules\CompanySettings\Contracts\BrandingSnapshotPort;
use App\Modules\CompanySettings\Data\BrandingSnapshotData;
use App\Modules\CompanySettings\Models\OrganizationSettings;

final class DatabaseBrandingSnapshotProvider implements BrandingSnapshotPort
{
    public function current(): BrandingSnapshotData
    {
        return BrandingSnapshotData::fromSettings(OrganizationSettings::current());
    }
}

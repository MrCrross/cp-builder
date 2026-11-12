<?php

namespace App\Modules\CompanySettings\Application\Contracts;

use App\Modules\CompanySettings\Application\Data\BrandingSnapshotData;

/**
 * Порт брендинга (§10.1/§10.2): модули получают снимок брендинга через него,
 * а не читают глобальные настройки напрямую.
 */
interface BrandingSnapshotPort
{
    public function current(): BrandingSnapshotData;
}

<?php

namespace App\Modules\CompanySettings\Authorization;

use App\Models\User;
use App\Modules\CompanySettings\Infrastructure\Persistence\BrandingAsset;
use App\Modules\CompanySettings\Infrastructure\Persistence\OrganizationSettings;

/**
 * Брендинг и настройки организации — только administrator через permission
 * branding.manage (матрица плана §9.2).
 */
class BrandingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('branding.manage');
    }

    public function update(User $user, OrganizationSettings $settings): bool
    {
        return $user->can('branding.manage');
    }

    public function uploadLogo(User $user): bool
    {
        return $user->can('branding.manage');
    }

    public function deleteLogo(User $user, BrandingAsset $asset): bool
    {
        return $user->can('branding.manage');
    }

    public function viewLogo(User $user): bool
    {
        return $user->can('branding.manage');
    }
}

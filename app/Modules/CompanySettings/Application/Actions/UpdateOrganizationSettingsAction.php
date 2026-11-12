<?php

namespace App\Modules\CompanySettings\Application\Actions;

use App\Models\User;
use App\Modules\CompanySettings\Infrastructure\Persistence\OrganizationSettings;
use App\Support\Audit\Audit;

/**
 * Обновление настроек организации (§10.1). Только administrator (Policy);
 * после сохранения инвалидируется кэш, изменение попадает в audit.
 */
final class UpdateOrganizationSettingsAction
{
    /**
     * @param  array{name: string, legal_name?: ?string, city?: ?string, website?: ?string, slogan?: ?string}  $data
     */
    public function execute(User $actor, array $data): OrganizationSettings
    {
        $settings = OrganizationSettings::current();

        $before = $settings->only(['name', 'legal_name', 'city', 'website', 'slogan']);
        $after = [
            'name' => $data['name'],
            'legal_name' => $data['legal_name'] ?? null,
            'city' => $data['city'] ?? null,
            'website' => $data['website'] ?? null,
            'slogan' => $data['slogan'] ?? null,
        ];

        $settings->fill($after);
        $settings->save();

        OrganizationSettings::flushCache();

        Audit::record($actor, 'organization-settings.updated', $settings, $before, $after);

        return $settings;
    }
}

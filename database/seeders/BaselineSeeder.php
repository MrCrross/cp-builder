<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class BaselineSeeder extends Seeder
{
    /**
     * Обязательные справочники: роли и permissions по матрице плана §9.2.
     * Idempotent — выполняется при первом развёртывании и в dev/test.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect([
            'proposals.view',
            'proposals.create',
            'proposals.update',
            'proposals.issue',
            'proposals.delete-draft',
            'proposals.archive',
            'proposals.restore',
            'proposals.rotate-link',
            'templates.manage',
            'projects.manage',
            'catalog.manage',
            'branding.manage',
            'users.manage',
            'audit.view',
            'imports.run',
            'integrations.manage',
        ])->mapWithKeys(fn (string $name) => [$name => Permission::findOrCreate($name)]);

        Role::findOrCreate('administrator')
            ->givePermissionTo($permissions->values());

        Role::findOrCreate('manager')
            ->givePermissionTo(
                $permissions->only([
                    'proposals.view',
                    'proposals.create',
                    'proposals.update',
                    'proposals.issue',
                    'proposals.delete-draft',
                    'proposals.archive',
                    'proposals.restore',
                    'proposals.rotate-link',
                    'templates.manage',
                    'projects.manage',
                    'catalog.manage',
                    'imports.run',
                ])->values(),
            );

        Role::findOrCreate('viewer')
            ->givePermissionTo([$permissions['proposals.view']]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

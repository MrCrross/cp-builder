<?php

namespace App\Modules\Imports\Policies;

use App\Models\User;
use App\Modules\Imports\Models\ImportJob;

/**
 * Доступ к импортам (план §9.2): операция менеджерская — permission
 * imports.run у manager/admin; viewer не имеет доступа даже на чтение
 * (импорты не являются рабочим справочником для read-only роли).
 */
class ImportJobPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('imports.run');
    }

    public function view(User $user, ImportJob $job): bool
    {
        return $user->can('imports.run');
    }

    public function create(User $user): bool
    {
        return $user->can('imports.run');
    }

    public function confirm(User $user, ImportJob $job): bool
    {
        return $user->can('imports.run');
    }

    public function cancel(User $user, ImportJob $job): bool
    {
        return $user->can('imports.run');
    }
}

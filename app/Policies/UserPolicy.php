<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Управление пользователями — только permission users.manage
     * (по матрице плана §9.2 это только administrator).
     */
    public function viewAny(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function update(User $user, User $model): bool
    {
        return $user->can('users.manage');
    }

    /**
     * Деактивировать самого себя нельзя — иначе контур остаётся без
     * управляющего доступа (план §9.2).
     */
    public function deactivate(User $user, User $model): bool
    {
        return $user->can('users.manage') && ! $user->is($model);
    }

    public function activate(User $user, User $model): bool
    {
        return $user->can('users.manage');
    }

    public function resendInvite(User $user, User $model): bool
    {
        return $user->can('users.manage');
    }
}

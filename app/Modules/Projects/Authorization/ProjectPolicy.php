<?php

namespace App\Modules\Projects\Authorization;

use App\Models\User;
use App\Modules\Projects\Infrastructure\Persistence\Project;

/**
 * Матрица доступа к проектам (план §9.2): viewer read-only,
 * manager/admin — полное управление. Owner не ограничивает
 * командную работу.
 */
class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('projects.view');
    }

    public function view(User $user, Project $project): bool
    {
        return $user->can('projects.view');
    }

    public function create(User $user): bool
    {
        return $user->can('projects.manage');
    }

    public function update(User $user, Project $project): bool
    {
        return $user->can('projects.manage');
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->can('projects.manage');
    }
}

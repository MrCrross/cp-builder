<?php

namespace App\Policies;

use App\Models\AuditEntry;
use App\Models\User;

class AuditEntryPolicy
{
    /**
     * Журнал аудита — только permission audit.view (план §9.2: administrator).
     */
    public function viewAny(User $user): bool
    {
        return $user->can('audit.view');
    }

    public function view(User $user, AuditEntry $auditEntry): bool
    {
        return $user->can('audit.view');
    }
}

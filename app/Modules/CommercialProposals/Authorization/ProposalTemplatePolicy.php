<?php

namespace App\Modules\CommercialProposals\Authorization;

use App\Models\User;
use App\Modules\CommercialProposals\Infrastructure\Persistence\ProposalTemplate;

/**
 * Шаблоны: просмотр — все с proposals.view, управление — templates.manage
 * (admin + manager, план §9.2).
 */
class ProposalTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('proposals.view');
    }

    public function manage(User $user, ?ProposalTemplate $template = null): bool
    {
        return $user->can('templates.manage');
    }
}

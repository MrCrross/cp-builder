<?php

namespace App\Modules\CommercialProposals\Policies;

use App\Models\User;
use App\Modules\CommercialProposals\Models\Proposal;

/**
 * Матрица доступа к КП (план §9.2): viewer read-only; manager/admin —
 * полный цикл. Owner не ограничивает командную работу (§9.2).
 */
class ProposalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('proposals.view');
    }

    public function view(User $user, Proposal $proposal): bool
    {
        return $user->can('proposals.view');
    }

    public function create(User $user): bool
    {
        return $user->can('proposals.create');
    }

    public function update(User $user, Proposal $proposal): bool
    {
        return $user->can('proposals.update');
    }

    public function issue(User $user, Proposal $proposal): bool
    {
        return $user->can('proposals.issue');
    }

    public function delete(User $user, Proposal $proposal): bool
    {
        // Только невыпущенный draft; действие дополнительно проверяется
        // в DeleteDraftProposalAction (план §7.1 п.7).
        return $user->can('proposals.delete-draft');
    }

    public function archive(User $user, Proposal $proposal): bool
    {
        return $user->can('proposals.archive');
    }

    public function restore(User $user, Proposal $proposal): bool
    {
        return $user->can('proposals.restore');
    }

    public function rotateLink(User $user, Proposal $proposal): bool
    {
        return $user->can('proposals.rotate-link');
    }
}

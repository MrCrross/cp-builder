<?php

namespace App\Modules\CommercialProposals\Actions;

use App\Models\User;
use App\Modules\CommercialProposals\Domain\Enums\ProposalStatus;
use App\Modules\CommercialProposals\Models\Proposal;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Восстановление архивного КП (план §7.1). Если ссылка была отозвана,
 * документ не рендерится до rotation — это явное действие пользователя.
 */
final class RestoreProposalAction
{
    public function execute(User $actor, Proposal $proposal): Proposal
    {
        return DB::transaction(function () use ($actor, $proposal) {
            /** @var Proposal $locked */
            $locked = Proposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ProposalStatus::Archived) {
                throw new ConflictHttpException('Восстановить можно только архивное КП.');
            }

            $locked->fill(['status' => ProposalStatus::Issued, 'archived_at' => null]);
            $locked->save();

            Audit::record($actor, 'proposal.restored', $locked, [], ['number' => $locked->number]);

            return $locked;
        });
    }
}

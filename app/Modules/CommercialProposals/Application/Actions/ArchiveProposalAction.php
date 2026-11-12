<?php

namespace App\Modules\CommercialProposals\Application\Actions;

use App\Models\User;
use App\Modules\CommercialProposals\Domain\ProposalStatus;
use App\Modules\CommercialProposals\Infrastructure\Persistence\Proposal;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Архивирование закрывает публичную ссылку (план §7.1): archived Proposal
 * не рендерится даже при валидном token. История и audit сохраняются.
 */
final class ArchiveProposalAction
{
    public function execute(User $actor, Proposal $proposal): Proposal
    {
        return DB::transaction(function () use ($actor, $proposal) {
            /** @var Proposal $locked */
            $locked = Proposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ProposalStatus::Issued) {
                throw new ConflictHttpException('Архивировать можно только выпущенное КП.');
            }

            $locked->fill(['status' => ProposalStatus::Archived, 'archived_at' => now()]);
            $locked->save();

            Audit::record($actor, 'proposal.archived', $locked, [], ['number' => $locked->number]);

            return $locked;
        });
    }
}

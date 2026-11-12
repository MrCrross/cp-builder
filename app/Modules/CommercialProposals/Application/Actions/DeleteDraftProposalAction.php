<?php

namespace App\Modules\CommercialProposals\Application\Actions;

use App\Models\User;
use App\Modules\CommercialProposals\Domain\ProposalStatus;
use App\Modules\CommercialProposals\Infrastructure\Persistence\Proposal;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Удаление возможно только для никогда не выпускавшегося draft
 * (план §7.1 п.7): версии/позиции/секции/ссылки удаляются каскадно
 * в одной транзакции. Подтверждение — на стороне UI.
 */
final class DeleteDraftProposalAction
{
    public function execute(User $actor, Proposal $proposal): void
    {
        DB::transaction(function () use ($actor, $proposal) {
            /** @var Proposal $locked */
            $locked = Proposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ProposalStatus::Draft) {
                throw new ConflictHttpException('Удалить можно только никогда не выпускавшийся черновик. Архивируйте документ.');
            }

            $title = $locked->draftVersion()->value('title');

            Audit::record($actor, 'proposal.deleted', null, [], [
                'title' => $title ?? ('#'.$locked->id),
            ]);

            $locked->delete();
        });
    }
}

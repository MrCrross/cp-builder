<?php

namespace App\Modules\CommercialProposals\Actions;

use App\Models\User;
use App\Modules\CommercialProposals\Models\ProposalBranch;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Назначение ветки основной (git-модель): флаг is_main переносится, после
 * чего постоянная main-ссылка предложения показывает текущую версию новой
 * main-ветки. Прежняя main остаётся обычной веткой без публичной ссылки;
 * её версии можно в любой момент отделить форком в новую ветку.
 */
final class PromoteBranchAction
{
    public function execute(User $actor, ProposalBranch $branch): ProposalBranch
    {
        return DB::transaction(function () use ($actor, $branch) {
            /** @var ProposalBranch $locked */
            $locked = ProposalBranch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_main) {
                return $locked;
            }

            /** @var ProposalBranch $previousMain */
            $previousMain = $locked->proposal->branches()->where('is_main', true)->lockForUpdate()->firstOrFail();

            $previousMain->fill(['is_main' => false])->save();
            $locked->fill(['is_main' => true])->save();

            Audit::record($actor, 'proposal.branch-promoted', $locked->proposal, [], [
                'branch' => $locked->name,
                'previous_main' => $previousMain->name,
            ]);

            return $locked;
        });
    }
}

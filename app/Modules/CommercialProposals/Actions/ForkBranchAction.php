<?php

namespace App\Modules\CommercialProposals\Actions;

use App\Models\User;
use App\Modules\CommercialProposals\Models\Proposal;
use App\Modules\CommercialProposals\Models\ProposalBranch;
use App\Modules\CommercialProposals\Models\ProposalVersion;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Форк версии в новую ветку (git-модель): ветка создаётся с указателем
 * на выбранную версию (любую — draft, issued, superseded; снапшот версии
 * неизменяем, поэтому у ветки сразу есть публичный документ) и получает
 * собственную публичную ссылку (токен хранится в БД, URL доступен всегда).
 * Редактирование — через новый черновик на ветке (CreateNewDraftFromIssuedAction).
 */
final class ForkBranchAction
{
    /**
     * @return array{branch: ProposalBranch, token: string}
     */
    public function execute(User $actor, Proposal $proposal, string $name, int $versionId): array
    {
        return DB::transaction(function () use ($actor, $proposal, $name, $versionId) {
            /** @var Proposal $locked */
            $locked = Proposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            $version = ProposalVersion::query()
                ->where('proposal_id', $locked->id)
                ->whereKey($versionId)
                ->first();

            if ($version === null) {
                throw new NotFoundHttpException('Версия не найдена в этом предложении.');
            }

            if ($locked->branches()->where('name', $name)->exists()) {
                throw new ConflictHttpException('Ветка с таким именем уже существует.');
            }

            /** @var ProposalBranch $branch */
            $branch = $locked->branches()->create([
                'name' => $name,
                'is_main' => false,
                'current_version_id' => $version->id,
            ]);

            $token = IssueProposalAction::generatePublicToken();

            $branch->publicLink()->create([
                'proposal_id' => $locked->id,
                'token' => $token,
                'token_hash' => hash('sha256', $token),
                'created_at' => now(),
            ]);

            Audit::record($actor, 'proposal.branch-forked', $locked, [], [
                'branch' => $branch->name,
                'version' => $version->version,
            ]);

            return ['branch' => $branch, 'token' => $token];
        });
    }
}

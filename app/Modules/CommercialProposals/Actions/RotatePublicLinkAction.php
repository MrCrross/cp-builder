<?php

namespace App\Modules\CommercialProposals\Actions;

use App\Models\User;
use App\Modules\CommercialProposals\Models\Proposal;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Rotation основной (main) публичной ссылки: старый token отзывается,
 * создаётся новый (хранится в БД, URL доступен всегда). Старая ссылка
 * перестаёт работать немедленно. Ссылки веток (branch_id not null) не
 * трогаем.
 */
final class RotatePublicLinkAction
{
    /**
     * @return array{token: string}
     */
    public function execute(User $actor, Proposal $proposal): array
    {
        return DB::transaction(function () use ($actor, $proposal) {
            /** @var Proposal $locked */
            $locked = Proposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            $active = $locked->publicLinks()->whereNull('branch_id')->whereNull('revoked_at')->lockForUpdate()->first();

            if ($active !== null) {
                $active->fill(['revoked_at' => now()]);
                $active->save();
            }

            $token = IssueProposalAction::generatePublicToken();

            $locked->publicLinks()->create([
                'token' => $token,
                'token_hash' => hash('sha256', $token),
                'created_at' => now(),
            ]);

            Audit::record($actor, 'proposal.link-rotated', $locked, [], ['number' => $locked->number]);

            return ['token' => $token];
        });
    }
}

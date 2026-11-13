<?php

namespace App\Modules\CommercialProposals\Actions;

use App\Models\User;
use App\Modules\CommercialProposals\Domain\Enums\ProposalStatus;
use App\Modules\CommercialProposals\Domain\Enums\VersionState;
use App\Modules\CommercialProposals\Models\Proposal;
use App\Modules\CommercialProposals\Models\ProposalBranch;
use App\Modules\CommercialProposals\Models\ProposalVersion;
use App\Modules\CompanySettings\Contracts\BrandingSnapshotPort;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Новый черновик на ветке из копии её текущей версии (git-семантика):
 * указатель ветки при этом не двигается — публичная страница меняется
 * только после выпуска. Client/manager snapshot'ы копируются как
 * редактируемые значения новой версии. По умолчанию — main-ветка.
 */
final class CreateNewDraftFromIssuedAction
{
    public function execute(User $actor, Proposal $proposal, ?int $branchId = null): ProposalVersion
    {
        return DB::transaction(function () use ($actor, $proposal, $branchId) {
            /** @var Proposal $locked */
            $locked = Proposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === ProposalStatus::Archived) {
                throw new ConflictHttpException('Архивное КП редактировать нельзя. Восстановите документ.');
            }

            /** @var ProposalBranch $branch */
            $branch = $locked->branches()->whereKey($branchId ?? $locked->mainBranch?->id)->lockForUpdate()->firstOrFail();

            if ($branch->versions()->where('state', VersionState::Draft)->exists()) {
                throw new ConflictHttpException('На ветке уже есть черновик.');
            }

            /** @var ProposalVersion $source */
            $source = $branch->currentVersion()->lockForUpdate()->firstOrFail();

            $nextVersion = ((int) $locked->versions()->max('version')) + 1;

            $version = ProposalVersion::query()->create([
                'proposal_id' => $locked->id,
                'branch_id' => $branch->id,
                'version' => $nextVersion,
                'state' => VersionState::Draft,
                'title' => $source->title,
                'subtitle' => $source->subtitle,
                'client_name' => $source->client_name,
                'client_contact' => $source->client_contact,
                'manager_name' => $source->manager_name,
                'manager_email' => $source->manager_email,
                'manager_phone' => $source->manager_phone,
                'note' => $source->note,
                'total_min' => $source->total_min,
                'total_max' => $source->total_max,
                'branding_snapshot' => app(BrandingSnapshotPort::class)->current()->toArray(),
                'created_by' => $actor->id,
            ]);

            $version->items()->createMany($source->items()->get()->map(fn ($item) => $item->only([
                'position', 'catalog_item_id', 'name', 'description', 'unit',
                'quantity_min', 'quantity_max', 'unit_price', 'amount_min', 'amount_max',
            ]))->all());

            $version->sections()->createMany($source->sections()->get()->map(fn ($section) => $section->only([
                'position', 'type', 'enabled', 'payload',
            ]))->all());

            Audit::record($actor, 'proposal.new-draft', $locked, [], [
                'version' => $version->version,
                'branch' => $branch->name,
            ]);

            return $version;
        });
    }
}

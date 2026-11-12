<?php

namespace App\Modules\CommercialProposals\Application\Actions;

use App\Models\User;
use App\Modules\CommercialProposals\Domain\ProposalStatus;
use App\Modules\CommercialProposals\Domain\VersionState;
use App\Modules\CommercialProposals\Infrastructure\Persistence\Proposal;
use App\Modules\CommercialProposals\Infrastructure\Persistence\ProposalVersion;
use App\Modules\CompanySettings\Application\Contracts\BrandingSnapshotPort;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Редактирование выпущенного КП создаёт новый draft из копии текущего
 * issued (план §7.1 п.3) — публичная страница при этом не меняется,
 * пока draft не выпущен. Client/manager snapshot'ы копируются как
 * редактируемые значения новой версии.
 */
final class CreateNewDraftFromIssuedAction
{
    public function execute(User $actor, Proposal $proposal): ProposalVersion
    {
        return DB::transaction(function () use ($actor, $proposal) {
            /** @var Proposal $locked */
            $locked = Proposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ProposalStatus::Issued) {
                throw new ConflictHttpException('Новый черновик создаётся только из выпущенного КП.');
            }

            if ($locked->draftVersion()->exists()) {
                throw new ConflictHttpException('Черновик уже существует.');
            }

            /** @var ProposalVersion $issued */
            $issued = $locked->issuedVersion()->lockForUpdate()->firstOrFail();

            $version = ProposalVersion::query()->create([
                'proposal_id' => $locked->id,
                'version' => $issued->version + 1,
                'state' => VersionState::Draft,
                'title' => $issued->title,
                'subtitle' => $issued->subtitle,
                'client_name' => $issued->client_name,
                'client_contact' => $issued->client_contact,
                'manager_name' => $issued->manager_name,
                'manager_email' => $issued->manager_email,
                'manager_phone' => $issued->manager_phone,
                'note' => $issued->note,
                'total_min' => $issued->total_min,
                'total_max' => $issued->total_max,
                'branding_snapshot' => app(BrandingSnapshotPort::class)->current()->toArray(),
                'created_by' => $actor->id,
            ]);

            $version->items()->createMany($issued->items()->get()->map(fn ($item) => $item->only([
                'position', 'catalog_item_id', 'name', 'description', 'unit',
                'quantity_min', 'quantity_max', 'unit_price', 'amount_min', 'amount_max',
            ]))->all());

            $version->sections()->createMany($issued->sections()->get()->map(fn ($section) => $section->only([
                'position', 'type', 'enabled', 'payload',
            ]))->all());

            Audit::record($actor, 'proposal.new-draft', $locked, [], ['version' => $version->version]);

            return $version;
        });
    }
}

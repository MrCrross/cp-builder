<?php

namespace App\Modules\CommercialProposals\Actions;

use App\Models\User;
use App\Modules\CommercialProposals\Domain\Enums\ProposalStatus;
use App\Modules\CommercialProposals\Domain\Enums\VersionState;
use App\Modules\CommercialProposals\Models\Proposal;
use App\Modules\CommercialProposals\Models\ProposalTemplate;
use App\Modules\CommercialProposals\Models\ProposalVersion;
use App\Modules\CompanySettings\Contracts\BrandingSnapshotPort;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Создание нового черновика КП — пустого или из шаблона (контент копируется,
 * §8.3). Публичного документа не создаёт (§7.1 п.1).
 */
final class CreateDraftProposalAction
{
    /**
     * @param  array{title?: string, subtitle?: ?string, client_name?: ?string, client_contact?: ?string, manager_name?: ?string, manager_email?: ?string, manager_phone?: ?string, note?: ?string, project_id?: ?int}  $data
     */
    public function execute(User $actor, ?int $templateId = null, array $data = []): Proposal
    {
        $template = $templateId === null ? null : ProposalTemplate::query()->findOrFail($templateId);

        return DB::transaction(function () use ($actor, $template, $data) {
            $proposal = Proposal::query()->create([
                'template_id' => $template?->id,
                'project_id' => $data['project_id'] ?? null,
                'owner_id' => $actor->id,
                'status' => ProposalStatus::Draft,
            ]);

            $content = $template === null ? [] : $template->default_content;

            $version = ProposalVersion::query()->create([
                'proposal_id' => $proposal->id,
                'version' => 1,
                'state' => VersionState::Draft,
                'title' => $data['title'] ?? ($template === null ? 'Коммерческое предложение' : $template->default_title),
                'subtitle' => $data['subtitle'] ?? $template?->default_subtitle,
                'client_name' => $data['client_name'] ?? null,
                'client_contact' => $data['client_contact'] ?? null,
                'manager_name' => $data['manager_name'] ?? $actor->name,
                'manager_email' => $data['manager_email'] ?? $actor->email,
                'manager_phone' => $data['manager_phone'] ?? null,
                'note' => $data['note'] ?? null,
                'branding_snapshot' => app(BrandingSnapshotPort::class)->current()->toArray(),
                'created_by' => $actor->id,
            ]);

            foreach ($content['items'] ?? [] as $position => $item) {
                $version->items()->create([
                    'position' => $position + 1,
                    ...$item,
                ]);
            }

            foreach ($content['sections'] ?? [] as $position => $section) {
                $version->sections()->create([
                    'position' => $position + 1,
                    ...$section,
                ]);
            }

            Audit::record($actor, 'proposal.created', $proposal, [], [
                'template' => $template?->name,
                'title' => $version->title,
                'project' => $proposal->project?->name,
            ]);

            return $proposal;
        });
    }
}

<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\CommercialProposals\Domain\Enums\VersionState;
use App\Modules\CommercialProposals\Models\Proposal;
use App\Modules\CommercialProposals\Models\ProposalBranch;
use App\Modules\CommercialProposals\Models\ProposalVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProposalVersion>
 */
class ProposalVersionFactory extends Factory
{
    protected $model = ProposalVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proposal_id' => Proposal::factory(),
            'branch_id' => function (array $attributes): int {
                $proposalId = $attributes['proposal_id'];

                // Существующая ветка предложения (обычно main) — чтобы
                // инварианты «один draft / один issued на ветку» повторяли
                // прежние инварианты на предложение.
                $existing = ProposalBranch::query()->where('proposal_id', $proposalId)->orderByDesc('is_main')->first();

                if ($existing !== null) {
                    return $existing->id;
                }

                return ProposalBranch::factory()->main()->create(['proposal_id' => $proposalId])->id;
            },
            'version' => function (array $attributes): int {
                return ((int) ProposalVersion::query()->where('proposal_id', $attributes['proposal_id'])->max('version')) + 1;
            },
            'state' => 'draft',
            'title' => 'Тестовое КП',
            'currency' => 'RUB',
            'total_min' => '0.00',
            'total_max' => '0.00',
            'branding_snapshot' => ['name' => 'Тест'],
            'created_by' => User::factory(),
        ];
    }

    /**
     * Указатель ветки следует за выпущенной версией — публичный резолв
     * через current_version_id работает и для фабричных сценариев.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (ProposalVersion $version): void {
            $branch = $version->branch;

            if ($branch === null) {
                return;
            }

            $shouldPoint = $version->state === VersionState::Issued
                || $branch->current_version_id === null
                || $version->version > $branch->currentVersion?->version;

            if ($shouldPoint) {
                $branch->fill(['current_version_id' => $version->id])->save();
            }
        });
    }
}

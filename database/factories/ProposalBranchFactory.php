<?php

namespace Database\Factories;

use App\Modules\CommercialProposals\Models\Proposal;
use App\Modules\CommercialProposals\Models\ProposalBranch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProposalBranch>
 */
class ProposalBranchFactory extends Factory
{
    protected $model = ProposalBranch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'proposal_id' => Proposal::factory(),
            'name' => fake()->unique()->slug(2),
            'is_main' => false,
            'current_version_id' => null,
        ];
    }

    /**
     * Main-ветка предложения (одна на предложение — unique index).
     */
    public function main(): static
    {
        return $this->state(fn (): array => ['name' => 'main', 'is_main' => true]);
    }
}

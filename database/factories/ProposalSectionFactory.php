<?php

namespace Database\Factories;

use App\Modules\CommercialProposals\Models\ProposalSection;
use App\Modules\CommercialProposals\Models\ProposalVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProposalSection>
 */
class ProposalSectionFactory extends Factory
{
    protected $model = ProposalSection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'version_id' => ProposalVersion::factory(),
            'position' => 1,
            'type' => 'custom',
            'enabled' => true,
            'payload' => ['blocks' => []],
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\CommercialProposals\Infrastructure\Persistence\Proposal;
use App\Modules\CommercialProposals\Infrastructure\Persistence\ProposalVersion;
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
            'version' => 1,
            'state' => 'draft',
            'title' => 'Тестовое КП',
            'currency' => 'RUB',
            'total_min' => '0.00',
            'total_max' => '0.00',
            'branding_snapshot' => ['name' => 'Тест'],
            'created_by' => User::factory(),
        ];
    }
}

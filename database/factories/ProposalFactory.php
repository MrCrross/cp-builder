<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\CommercialProposals\Infrastructure\Persistence\Proposal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Proposal>
 */
class ProposalFactory extends Factory
{
    protected $model = Proposal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'status' => 'draft',
        ];
    }
}

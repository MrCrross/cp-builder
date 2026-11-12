<?php

namespace Database\Factories;

use App\Modules\CommercialProposals\Infrastructure\Persistence\ProposalItem;
use App\Modules\CommercialProposals\Infrastructure\Persistence\ProposalVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProposalItem>
 */
class ProposalItemFactory extends Factory
{
    protected $model = ProposalItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'version_id' => ProposalVersion::factory(),
            'position' => 1,
            'name' => 'Проектные работы',
            'unit' => 'ч',
            'quantity_min' => '4.00',
            'quantity_max' => '8.00',
            'unit_price' => '1500.00',
            'amount_min' => '6000.00',
            'amount_max' => '12000.00',
        ];
    }
}

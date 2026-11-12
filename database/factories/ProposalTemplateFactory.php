<?php

namespace Database\Factories;

use App\Modules\CommercialProposals\Infrastructure\Persistence\ProposalTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProposalTemplate>
 */
class ProposalTemplateFactory extends Factory
{
    protected $model = ProposalTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Разработка сайта',
            'default_title' => 'Коммерческое предложение на разработку сайта',
            'default_content' => ['items' => [], 'sections' => []],
            'active' => true,
        ];
    }
}

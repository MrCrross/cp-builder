<?php

use App\Models\User;
use App\Modules\CommercialProposals\Actions\CreateDraftProposalAction;
use App\Modules\CommercialProposals\Actions\IssueProposalAction;
use App\Modules\CommercialProposals\Actions\SaveDraftAction;
use App\Modules\Statistics\Actions\RecordProposalVisitAction;
use Database\Seeders\BaselineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(BaselineSeeder::class));

function dashboardUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findByName($role));

    return $user;
}

function dashboardProposal(User $actor): array
{
    $proposal = (new CreateDraftProposalAction)->execute($actor);
    (new SaveDraftAction)->execute($proposal, [
        'title' => 'КП дашборд',
        'items' => [[
            'name' => 'Работы',
            'unit' => 'ч',
            'quantity_min' => '1',
            'quantity_max' => '1',
            'unit_price' => '1000.00',
        ]],
        'sections' => [],
        'lock_version' => 1,
    ]);
    ['token' => $token] = (new IssueProposalAction)->execute($actor, $proposal);

    return [$proposal->refresh(), $token];
}

it('requires authentication', function () {
    $this->get(route('statistics.index'))->assertRedirect(route('login'));
});

it('forbids viewers', function () {
    $this->actingAs(dashboardUser('viewer'))
        ->get(route('statistics.index'))
        ->assertForbidden();
});

it('renders dashboard with totals matching tracked events', function () {
    $actor = dashboardUser('manager');
    [$proposalA] = dashboardProposal($actor);
    [$proposalB] = dashboardProposal($actor);

    $visit = new RecordProposalVisitAction;
    $visit->execute($proposalA, '203.0.113.10', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0');
    $visit->execute($proposalA, '203.0.113.10', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0');
    $visit->execute($proposalA, '203.0.113.20', 'curl/8.5.0');
    $visit->execute($proposalB, '203.0.113.30', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Mobile/15E148');

    $response = $this->actingAs($actor)->get(route('statistics.index'));

    $response->assertOk();

    $response->assertInertia(fn ($page) => $page
        ->component('statistics/StatisticsPage')
        ->where('totals', [
            'visits' => 3,
            'unique_visitors' => 2,
            'bot_requests' => 1,
            'proposals_with_visits' => 2,
        ])
        ->has('series', 30)
        ->where('breakdowns.device_type.0.value', 'desktop')
        ->where('breakdowns.device_type.0.visits', 2)
        ->where('breakdowns.device_type.1.value', 'mobile')
        ->has('proposals', 2)
        ->where('filters.period', 30)
        ->where('filters.proposal_id', null)
        ->has('freshness.last_visit_at')
    );
});

it('filters metrics by proposal', function () {
    $actor = dashboardUser('administrator');
    [$proposalA] = dashboardProposal($actor);
    [$proposalB] = dashboardProposal($actor);

    $visit = new RecordProposalVisitAction;
    $visit->execute($proposalA, '203.0.113.10', 'Chrome/120.0 Windows');
    $visit->execute($proposalB, '203.0.113.10', 'Chrome/120.0 Windows');

    $response = $this->actingAs($actor)->get(route('statistics.index', ['proposal_id' => $proposalA->id]));

    $response->assertInertia(fn ($page) => $page
        ->where('totals.visits', 1)
        ->where('totals.unique_visitors', 1)
        ->where('totals.proposals_with_visits', 1)
        ->where('filters.proposal_id', $proposalA->id)
        ->has('proposals', 1)
    );
});

it('falls back to the default period for unknown values', function (string $query) {
    $actor = dashboardUser('manager');

    $response = $this->actingAs($actor)->get(route('statistics.index').'?'.$query);

    $response->assertInertia(fn ($page) => $page->where('filters.period', 30));
})->with([
    'invalid period' => ['period=14'],
    'non-numeric period' => ['period=abc'],
]);

it('ignores unknown proposal filter values', function () {
    $actor = dashboardUser('manager');

    $response = $this->actingAs($actor)->get(route('statistics.index', ['proposal_id' => 424242]));

    $response->assertInertia(fn ($page) => $page->where('filters.proposal_id', null));
});

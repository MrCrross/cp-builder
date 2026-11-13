<?php

use App\Models\User;
use App\Modules\CommercialProposals\Actions\CreateDraftProposalAction;
use App\Modules\CommercialProposals\Actions\SaveDraftAction;
use App\Modules\CommercialProposals\Models\Proposal;
use App\Modules\CommercialProposals\Models\ProposalTemplate;
use Database\Seeders\BaselineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(BaselineSeeder::class));

function userWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findByName($role));

    return $user;
}

function httpDraftPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'КП через HTTP',
        'items' => [[
            'name' => 'Работа',
            'unit' => 'ч',
            'quantity_min' => '2',
            'quantity_max' => '4',
            'unit_price' => '1000.00',
        ]],
        'sections' => [],
        'lock_version' => 1,
    ], $overrides);
}

it('lists proposals for authenticated users and filters by status', function () {
    $manager = userWithRole('manager');

    $proposal = (new CreateDraftProposalAction)->execute($manager);
    (new SaveDraftAction)->execute($proposal, httpDraftPayload());

    $this->actingAs($manager)
        ->get(route('proposals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('proposals-index/ProposalsIndexPage')
            ->has('proposals.data', 1)
            ->where('proposals.data.0.status', 'draft')
        );

    $this->actingAs($manager)
        ->get(route('proposals.index', ['status' => 'issued']))
        ->assertInertia(fn ($page) => $page->has('proposals.data', 0));
});

it('hides proposals registry from guests and keeps viewer read-only over http', function () {
    $viewer = userWithRole('viewer');

    $this->get(route('proposals.index'))->assertRedirect(route('login'));

    $this->actingAs($viewer)->get(route('proposals.index'))->assertOk();
    $this->actingAs($viewer)->get(route('proposals.create'))->assertForbidden();

    $proposal = Proposal::factory()->create();

    $this->actingAs($viewer)
        ->put(route('proposals.update', $proposal), httpDraftPayload())
        ->assertForbidden();

    $this->actingAs($viewer)
        ->post(route('proposals.issue', $proposal))
        ->assertForbidden();
});

it('creates a proposal via http and redirects to editor', function () {
    $manager = userWithRole('manager');

    $this->actingAs($manager)
        ->post(route('proposals.store'))
        ->assertRedirect(route('proposals.edit', Proposal::query()->sole()));
});

it('saves draft via http with server-side totals', function () {
    $manager = userWithRole('manager');
    $proposal = (new CreateDraftProposalAction)->execute($manager);

    $this->actingAs($manager)
        ->put(route('proposals.update', $proposal), httpDraftPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($proposal->draftOnBranch()->refresh()->total_max)->toBe('4000.00');
});

it('issues via http and exposes the permanent public url in the editor payload', function () {
    $manager = userWithRole('manager');
    $proposal = (new CreateDraftProposalAction)->execute($manager);
    (new SaveDraftAction)->execute($proposal, httpDraftPayload());

    $this->actingAs($manager)
        ->post(route('proposals.issue', $proposal))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $token = $proposal->publicLinks()->sole()->token;

    expect($token)->not->toBeNull();

    $url = route('kp.show', ['token' => $token]);

    // Одноразового flash больше нет: URL постоянно доступен в пейлоаде редактора.
    $this->actingAs($manager)
        ->get(route('proposals.edit', $proposal))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('proposal.has_public_link', true)
            ->where('proposal.public_url', $url)
            ->where('proposal.branches.0.public_url', $url)
        );

    $this->get($url)->assertOk();
});

it('exposes null public url for legacy links created before token storage', function () {
    $manager = userWithRole('manager');
    $proposal = (new CreateDraftProposalAction)->execute($manager);
    (new SaveDraftAction)->execute($proposal, httpDraftPayload());

    $this->actingAs($manager)->post(route('proposals.issue', $proposal));

    // Имитация строки, созданной до перехода на хранение токена.
    $proposal->publicLinks()->whereNull('revoked_at')->update(['token' => null]);

    $this->actingAs($manager)
        ->get(route('proposals.edit', $proposal))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('proposal.has_public_link', true)
            ->where('proposal.public_url', null)
            ->where('proposal.branches.0.has_public_link', true)
            ->where('proposal.branches.0.public_url', null)
        );
});

it('rate limits issue endpoint to 10 per minute', function () {
    $manager = userWithRole('manager');

    $responses = collect(range(1, 11))->map(function () use ($manager) {
        $proposal = (new CreateDraftProposalAction)->execute($manager);
        (new SaveDraftAction)->execute($proposal, httpDraftPayload());

        return $this->actingAs($manager)->post(route('proposals.issue', $proposal));
    });

    expect($responses->last()->status())->toBe(429);
});

it('previews drafts only for authenticated with policy', function () {
    $manager = userWithRole('manager');
    $viewer = userWithRole('viewer');

    $proposal = (new CreateDraftProposalAction)->execute($manager);
    (new SaveDraftAction)->execute($proposal, httpDraftPayload());

    $this->get(route('proposals.preview', $proposal))->assertRedirect(route('login'));
    $this->actingAs($viewer)->get(route('proposals.preview', $proposal))->assertOk();
    $this->actingAs($manager)->get(route('proposals.preview', $proposal))->assertOk();
});

it('manages templates with manage permission only', function () {
    $manager = userWithRole('manager');
    $viewer = userWithRole('viewer');

    $this->actingAs($viewer)
        ->post(route('templates.store'), ['name' => 'X', 'default_title' => 'Y'])
        ->assertForbidden();

    $this->actingAs($manager)
        ->post(route('templates.store'), [
            'name' => 'Типовое КП',
            'default_title' => 'Коммерческое предложение',
            'default_content' => ['items' => [], 'sections' => []],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $template = ProposalTemplate::query()->sole();

    $this->actingAs($manager)
        ->put(route('templates.update', $template), [
            'name' => 'Типовое КП v2',
            'default_title' => 'Коммерческое предложение',
        ])
        ->assertRedirect();

    expect($template->refresh()->name)->toBe('Типовое КП v2');

    $this->actingAs($viewer)
        ->delete(route('templates.destroy', $template))
        ->assertForbidden();
});

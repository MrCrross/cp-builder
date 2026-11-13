<?php

use App\Models\User;
use App\Modules\CommercialProposals\Actions\CreateDraftProposalAction;
use App\Modules\CommercialProposals\Actions\CreateNewDraftFromIssuedAction;
use App\Modules\CommercialProposals\Actions\ForkBranchAction;
use App\Modules\CommercialProposals\Actions\IssueProposalAction;
use App\Modules\CommercialProposals\Actions\PromoteBranchAction;
use App\Modules\CommercialProposals\Actions\RotatePublicLinkAction;
use App\Modules\CommercialProposals\Actions\SaveDraftAction;
use App\Modules\CommercialProposals\Models\Proposal;
use App\Modules\CommercialProposals\Models\ProposalVersion;
use Database\Seeders\BaselineSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(BaselineSeeder::class));

function branchesActor(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findByName('manager'));

    return $user;
}

function branchesPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'КП ветки',
        'lock_version' => 1,
    ], $overrides);
}

/** Предложение, выпущенное на main с активной main-ссылкой. */
function branchesIssuedProposal(User $actor, string $title = 'КП v1'): array
{
    $proposal = (new CreateDraftProposalAction)->execute($actor);
    (new SaveDraftAction)->execute($proposal, branchesPayload(['title' => $title]));

    ['token' => $token] = (new IssueProposalAction)->execute($actor, $proposal);

    return [$proposal->refresh(), $token];
}

/** Сохранить черновик ветки с корректным lock_version. */
function branchesSaveDraft(Proposal $proposal, string $title, ?int $branchId = null): void
{
    $draft = $proposal->draftOnBranch($branchId);

    (new SaveDraftAction)->execute($proposal, branchesPayload([
        'title' => $title,
        'lock_version' => $draft->lock_version,
        'branch_id' => $branchId,
    ]));
}

it('creates main branch together with the first draft', function () {
    $actor = branchesActor();
    $proposal = (new CreateDraftProposalAction)->execute($actor);

    $branch = $proposal->branches()->sole();

    expect($branch->name)->toBe('main')
        ->and($branch->is_main)->toBeTrue()
        ->and($branch->current_version_id)->not->toBeNull();
});

it('moves main branch pointer on issue keeping the public link permanent', function () {
    $actor = branchesActor();
    [$proposal, $token] = branchesIssuedProposal($actor);

    // Вторая версия на main: ссылка та же, документ по ней обновился.
    (new CreateNewDraftFromIssuedAction)->execute($actor, $proposal);
    branchesSaveDraft($proposal, 'КП v2');
    (new IssueProposalAction)->execute($actor, $proposal->refresh());

    expect($proposal->publicLinks()->whereNull('revoked_at')->whereNull('branch_id')->count())->toBe(1)
        ->and($proposal->refresh()->mainBranch->currentVersion->title)->toBe('КП v2')
        ->and($proposal->mainBranch->currentVersion->version)->toBe(2);

    $this->get(route('kp.show', ['token' => $token]))
        ->assertOk()
        ->assertSee('КП v2');
});

it('forks a version into a new branch with its own public link', function () {
    $actor = branchesActor();
    [$proposal, $mainToken] = branchesIssuedProposal($actor, 'КП основное');

    $mainVersion = $proposal->mainBranch->currentVersion;

    ['branch' => $branch, 'token' => $branchToken] = (new ForkBranchAction)->execute($actor, $proposal, 'skidka', $mainVersion->id);

    expect($branch->is_main)->toBeFalse()
        ->and($branch->current_version_id)->toBe($mainVersion->id)
        ->and($branchToken)->not->toBe($mainToken);

    // Ссылка ветки показывает форкнутую версию.
    $this->get(route('kp.show', ['token' => $branchToken]))
        ->assertOk()
        ->assertSee('КП основное');

    // Main-ссылка не изменилась.
    $this->get(route('kp.show', ['token' => $mainToken]))->assertOk();
});

it('keeps branch document fixed while main moves on', function () {
    $actor = branchesActor();
    [$proposal, $mainToken] = branchesIssuedProposal($actor, 'КП v1');

    $v1 = $proposal->mainBranch->currentVersion;
    ['token' => $branchToken] = (new ForkBranchAction)->execute($actor, $proposal, 'variant', $v1->id);

    // Main ушла вперёд: v1 устарела (superseded).
    (new CreateNewDraftFromIssuedAction)->execute($actor, $proposal);
    branchesSaveDraft($proposal, 'КП v2');
    (new IssueProposalAction)->execute($actor, $proposal->refresh());

    expect($v1->refresh()->state->value)->toBe('superseded');

    // Ссылка ветки по-прежнему показывает v1.
    $this->get(route('kp.show', ['token' => $branchToken]))
        ->assertOk()
        ->assertSee('КП v1');

    // Main-ссылка — v2.
    $this->get(route('kp.show', ['token' => $mainToken]))
        ->assertOk()
        ->assertSee('КП v2');
});

it('promotes a branch to main so the permanent main link shows its tip', function () {
    $actor = branchesActor();
    [$proposal, $mainToken] = branchesIssuedProposal($actor, 'КП v1');

    $v1 = $proposal->mainBranch->currentVersion;
    ['branch' => $branch, 'token' => $branchToken] = (new ForkBranchAction)->execute($actor, $proposal, 'skidka', $v1->id);

    // Ветка развивается независимо: новая версия на ветке.
    (new CreateNewDraftFromIssuedAction)->execute($actor, $proposal, $branch->id);
    branchesSaveDraft($proposal, 'КП со скидкой', $branch->id);
    (new IssueProposalAction)->execute($actor, $proposal->refresh(), $branch->id);

    // До promote main-ссылка показывает v1.
    $this->get(route('kp.show', ['token' => $mainToken]))->assertSee('КП v1');

    (new PromoteBranchAction)->execute($actor, $branch->refresh());

    expect($branch->refresh()->is_main)->toBeTrue()
        ->and($proposal->mainBranch()->sole()->id)->toBe($branch->id);

    // Main-ссылка теперь показывает текущую версию новой main-ветки.
    $this->get(route('kp.show', ['token' => $mainToken]))
        ->assertOk()
        ->assertSee('КП со скидкой');
});

it('keeps drafts isolated per branch', function () {
    $actor = branchesActor();
    [$proposal] = branchesIssuedProposal($actor);

    $v1 = $proposal->mainBranch->currentVersion;
    ['branch' => $branch] = (new ForkBranchAction)->execute($actor, $proposal, 'variant', $v1->id);

    (new CreateNewDraftFromIssuedAction)->execute($actor, $proposal, $branch->id);

    expect($proposal->draftOnBranch($branch->id))->not->toBeNull()
        ->and($proposal->draftOnBranch())->toBeNull(); // на main черновика нет

    // Вторая версия на main не мешает черновику ветки.
    (new CreateNewDraftFromIssuedAction)->execute($actor, $proposal);
    expect($proposal->versions()->where('state', 'draft')->count())->toBe(2);

    // Дубликат draft на той же ветке запрещён на уровне БД.
    try {
        ProposalVersion::query()->create([
            'proposal_id' => $proposal->id,
            'branch_id' => $branch->id,
            'version' => 99,
            'state' => 'draft',
            'title' => 'Дубликат',
            'branding_snapshot' => [],
            'created_by' => $actor->id,
        ]);
        $this->fail('Ожидалось нарушение уникальности draft на ветке');
    } catch (QueryException) {
        expect(true)->toBeTrue();
    }
});

it('exposes branches in the editor payload', function () {
    $actor = branchesActor();
    [$proposal, $token] = branchesIssuedProposal($actor, 'КП v1');

    $response = $this->actingAs($actor)->get(route('proposals.edit', $proposal));

    $response->assertOk();

    $payload = $response->viewData('page')['props']['proposal'];

    expect($payload['branches'])->toHaveCount(1)
        ->and($payload['branches'][0]['name'])->toBe('main')
        ->and($payload['branches'][0]['is_main'])->toBeTrue()
        ->and($payload['branches'][0]['has_public_link'])->toBeTrue()
        ->and($payload['branches'][0]['public_url'])->toBe(route('kp.show', ['token' => $token]))
        ->and($payload['has_public_link'])->toBeTrue()
        ->and($payload['public_url'])->toBe(route('kp.show', ['token' => $token]))
        ->and($payload['active_branch'])->toBe('main')
        ->and($payload['versions'])->toHaveCount(1);
});

it('rejects fork with a version from another proposal', function () {
    $actor = branchesActor();
    [$proposal] = branchesIssuedProposal($actor);
    [$other] = branchesIssuedProposal($actor);

    $foreignVersion = $other->mainBranch->currentVersion;

    $this->expectException(NotFoundHttpException::class);

    (new ForkBranchAction)->execute($actor, $proposal, 'x', $foreignVersion->id);
});

it('rotates only the main link and keeps branch links intact', function () {
    $actor = branchesActor();
    [$proposal, $mainToken] = branchesIssuedProposal($actor, 'КП v1');

    $v1 = $proposal->mainBranch->currentVersion;
    ['branch' => $branch, 'token' => $branchToken] = (new ForkBranchAction)->execute($actor, $proposal, 'variant', $v1->id);

    ['token' => $newMainToken] = (new RotatePublicLinkAction)->execute($actor, $proposal->refresh());

    // Старая main-ссылка отозвана, новая активна и хранится в БД.
    $this->get(route('kp.show', ['token' => $mainToken]))->assertNotFound();
    $this->get(route('kp.show', ['token' => $newMainToken]))->assertOk();

    // Ссылка ветки не тронута.
    expect($branch->refresh()->publicLink->token)->toBe($branchToken);
    $this->get(route('kp.show', ['token' => $branchToken]))->assertOk();
});

it('exposes branch public url in the editor payload', function () {
    $actor = branchesActor();
    [$proposal] = branchesIssuedProposal($actor, 'КП v1');

    $v1 = $proposal->mainBranch->currentVersion;
    (new ForkBranchAction)->execute($actor, $proposal, 'skidka', $v1->id);

    $payload = $this->actingAs($actor)
        ->get(route('proposals.edit', $proposal))
        ->viewData('page')['props']['proposal'];

    $branchRow = collect($payload['branches'])->firstWhere('name', 'skidka');

    expect($branchRow['has_public_link'])->toBeTrue()
        ->and($branchRow['public_url'])->toStartWith(url('/kp/'));

    $this->get($branchRow['public_url'])->assertOk();
});

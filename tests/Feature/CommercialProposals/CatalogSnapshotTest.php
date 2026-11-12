<?php

use App\Models\AuditEntry;
use App\Models\User;
use App\Modules\Catalog\Application\Actions\CreateCatalogItemAction;
use App\Modules\Catalog\Application\Actions\UpdateCatalogItemAction;
use App\Modules\Catalog\Infrastructure\Persistence\CatalogItem;
use App\Modules\CommercialProposals\Application\Actions\CreateDraftProposalAction;
use App\Modules\CommercialProposals\Application\Actions\DraftCatalogDiffAction;
use App\Modules\CommercialProposals\Application\Actions\IssueProposalAction;
use App\Modules\CommercialProposals\Application\Actions\RefreshDraftItemsFromCatalogAction;
use App\Modules\CommercialProposals\Application\Actions\SaveDraftAction;
use App\Modules\Projects\Infrastructure\Persistence\Project;
use Database\Seeders\BaselineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(BaselineSeeder::class));

function snapshotUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findByName($role));

    return $user;
}

function snapshotDraftPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'КП со справочником',
        'items' => [],
        'sections' => [],
        'lock_version' => 1,
    ], $overrides);
}

function linkedItem(CatalogItem $item, array $overrides = []): array
{
    return array_merge([
        'catalog_item_id' => $item->id,
        'name' => $item->name,
        'unit' => $item->unit,
        'quantity_min' => '2',
        'quantity_max' => '4',
        'unit_price' => $item->price,
    ], $overrides);
}

it('preserves catalog lineage through save round-trip and rejects unknown ids', function () {
    $manager = snapshotUser('manager');
    $item = (new CreateCatalogItemAction)->execute($manager, ['name' => 'Настройка модуля', 'unit' => 'ч', 'price' => '1500.00']);
    $proposal = (new CreateDraftProposalAction)->execute($manager);

    (new SaveDraftAction)->execute($proposal, snapshotDraftPayload([
        'items' => [linkedItem($item)],
    ]));

    expect($proposal->draftVersion->items()->sole()->catalog_item_id)->toBe($item->id);

    // Повторное сохранение: lineage не теряется (рандеву catalog_item_id).
    (new SaveDraftAction)->execute($proposal, snapshotDraftPayload([
        'items' => [linkedItem($item)],
        'lock_version' => 2,
    ]));
    expect($proposal->draftVersion->items()->sole()->catalog_item_id)->toBe($item->id);

    // Несуществующая позиция справочника — 422 на HTTP-уровне.
    $this->actingAs($manager)
        ->put(route('proposals.update', $proposal), snapshotDraftPayload([
            'items' => [linkedItem($item, ['catalog_item_id' => 99999])],
            'lock_version' => 3,
        ]))
        ->assertSessionHasErrors(['items.0.catalog_item_id']);
});

it('assigns and reassigns project on draft save', function () {
    $manager = snapshotUser('manager');
    $projectA = Project::factory()->create(['created_by' => $manager->id]);
    $projectB = Project::factory()->create(['created_by' => $manager->id]);

    $proposal = (new CreateDraftProposalAction)->execute($manager, null, ['project_id' => $projectA->id]);
    expect($proposal->project_id)->toBe($projectA->id);

    (new SaveDraftAction)->execute($proposal, snapshotDraftPayload(['project_id' => $projectB->id]));
    expect($proposal->refresh()->project_id)->toBe($projectB->id);

    (new SaveDraftAction)->execute($proposal, snapshotDraftPayload(['project_id' => null, 'lock_version' => 2]));
    expect($proposal->refresh()->project_id)->toBeNull();
});

it('shows diff between draft snapshot and catalog before refresh', function () {
    $manager = snapshotUser('manager');
    $item = (new CreateCatalogItemAction)->execute($manager, ['name' => 'Работа А', 'unit' => 'ч', 'price' => '100.00']);
    $proposal = (new CreateDraftProposalAction)->execute($manager);
    (new SaveDraftAction)->execute($proposal, snapshotDraftPayload(['items' => [linkedItem($item)]]));

    expect(app(DraftCatalogDiffAction::class)->execute($proposal)[0]['changed'] ?? null)->toBeFalse();

    (new UpdateCatalogItemAction)->execute($manager, $item, ['name' => 'Работа Б', 'unit' => 'ч', 'price' => '250.00']);

    $diff = app(DraftCatalogDiffAction::class)->execute($proposal)[0];
    expect($diff['changed'])->toBeTrue()
        ->and($diff['name']['catalog'])->toBe('Работа Б')
        ->and($diff['unit_price']['catalog'])->toBe('250.00');

    $response = $this->actingAs($manager)
        ->get(route('proposals.catalog-diff', $proposal))
        ->assertOk();
    expect($response->json('diff.0.changed'))->toBeTrue();
});

it('refreshes linked draft rows from catalog recomputing totals and bumping lock', function () {
    $manager = snapshotUser('manager');
    $item = (new CreateCatalogItemAction)->execute($manager, ['name' => 'Работа А', 'unit' => 'ч', 'price' => '100.00']);
    $freeItem = ['name' => 'Свободная строка', 'quantity_min' => '1', 'quantity_max' => '1', 'unit_price' => '10.00'];

    $proposal = (new CreateDraftProposalAction)->execute($manager);
    (new SaveDraftAction)->execute($proposal, snapshotDraftPayload([
        'items' => [linkedItem($item), $freeItem],
    ]));

    (new UpdateCatalogItemAction)->execute($manager, $item, ['name' => 'Работа Б', 'unit' => 'ч', 'price' => '250.00']);

    ['updated' => $updated] = app(RefreshDraftItemsFromCatalogAction::class)->execute($manager, $proposal);
    expect($updated)->toBe(1);

    $version = $proposal->draftVersion->refresh();
    $linked = $version->items()->whereNotNull('catalog_item_id')->sole();
    $free = $version->items()->whereNull('catalog_item_id')->sole();

    expect($linked->name)->toBe('Работа Б')
        ->and($linked->unit_price)->toBe('250.00')
        ->and($linked->amount_min)->toBe('500.00')
        ->and($linked->amount_max)->toBe('1000.00')
        ->and($free->name)->toBe('Свободная строка')
        ->and($version->total_min)->toBe('510.00')
        ->and($version->total_max)->toBe('1010.00')
        ->and($version->lock_version)->toBe(3)
        ->and(AuditEntry::query()->where('action', 'proposal.catalog-refreshed')->exists())->toBeTrue();

    $this->actingAs($manager)
        ->post(route('proposals.refresh-catalog', $proposal))
        ->assertRedirect();
});

it('keeps issued versions immutable: refresh requires a draft', function () {
    $manager = snapshotUser('manager');
    $item = (new CreateCatalogItemAction)->execute($manager, ['name' => 'Работа', 'unit' => 'ч', 'price' => '100.00']);

    $proposal = (new CreateDraftProposalAction)->execute($manager);
    (new SaveDraftAction)->execute($proposal, snapshotDraftPayload(['items' => [linkedItem($item)]]));
    (new IssueProposalAction)->execute($manager, $proposal);

    $this->actingAs($manager)
        ->post(route('proposals.refresh-catalog', $proposal))
        ->assertNotFound();

    expect($proposal->issuedVersion->items()->sole()->unit_price)->toBe('100.00');
});

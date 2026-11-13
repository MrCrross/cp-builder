<?php

use App\Models\AuditEntry;
use App\Models\User;
use App\Modules\Catalog\Actions\CreateCatalogItemAction;
use App\Modules\Catalog\Actions\DeleteCategoryAction;
use App\Modules\Catalog\Actions\UpdateCatalogItemAction;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\CommercialProposals\Actions\CreateDraftProposalAction;
use App\Modules\CommercialProposals\Actions\SaveDraftAction;
use Database\Seeders\BaselineSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(BaselineSeeder::class));

function catalogUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findByName($role));

    return $user;
}

it('lists categories and items with filters and pagination', function () {
    $manager = catalogUser('manager');
    $category = CatalogCategory::factory()->create(['name' => 'Разработка']);
    CatalogItem::factory()->count(2)->create(['category_id' => $category->id, 'name' => 'Интеграция модуля']);
    CatalogItem::factory()->create(['name' => 'Дизайн лендинга']);

    $this->actingAs($manager)
        ->get(route('catalog.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('catalog/CatalogPage')
            ->has('categories', 2)
            ->has('items.data', 3)
        );

    $this->actingAs($manager)
        ->get(route('catalog.index', ['search' => 'Интеграция']))
        ->assertInertia(fn ($page) => $page->has('items.data', 2));

    $this->actingAs($manager)
        ->get(route('catalog.index', ['category_id' => $category->id]))
        ->assertInertia(fn ($page) => $page
            ->has('items.data', 2)
            ->where('items.data.0.category.name', 'Разработка')
        );
});

it('creates categories and items via http with audit and price normalization', function () {
    $manager = catalogUser('manager');

    $this->actingAs($manager)
        ->post(route('catalog.categories.store'), ['name' => 'Лицензии'])
        ->assertRedirect();
    $category = CatalogCategory::query()->sole();

    $this->actingAs($manager)
        ->post(route('catalog.items.store'), [
            'name' => 'Платная лицензия',
            'category_id' => $category->id,
            'unit' => 'шт',
            'price' => '12.3',
        ])
        ->assertRedirect();

    $item = CatalogItem::query()->sole();
    expect($item->price)->toBe('12.30')
        ->and($item->currency)->toBe('RUB')
        ->and(AuditEntry::query()->where('action', 'catalog-item.created')->exists())->toBeTrue();
});

it('keeps viewer read-only over http', function () {
    $viewer = catalogUser('viewer');
    $manager = catalogUser('manager');
    $item = CatalogItem::factory()->create();
    $category = CatalogCategory::factory()->create();

    $this->get(route('catalog.index'))->assertRedirect(route('login'));
    $this->actingAs($viewer)->get(route('catalog.index'))->assertOk();

    $this->actingAs($viewer)->post(route('catalog.categories.store'), ['name' => 'X'])->assertForbidden();
    $this->actingAs($viewer)->put(route('catalog.categories.update', $category), ['name' => 'Y'])->assertForbidden();
    $this->actingAs($viewer)->delete(route('catalog.categories.destroy', $category))->assertForbidden();
    $this->actingAs($viewer)->post(route('catalog.items.store'), ['name' => 'X', 'price' => '1.00'])->assertForbidden();
    $this->actingAs($viewer)->put(route('catalog.items.update', $item), ['name' => 'Y', 'price' => '1.00'])->assertForbidden();
    $this->actingAs($viewer)->delete(route('catalog.items.destroy', $item))->assertForbidden();

    expect($item->refresh()->name)->not->toBe('Y');
});

it('refuses to delete a category with items', function () {
    $manager = catalogUser('manager');
    $category = CatalogCategory::factory()->create();
    CatalogItem::factory()->create(['category_id' => $category->id]);

    $this->actingAs($manager)
        ->delete(route('catalog.categories.destroy', $category))
        ->assertStatus(409);

    expect(fn () => (new DeleteCategoryAction)
        ->execute($manager, $category))->toThrow(ConflictHttpException::class)
        ->and(CatalogCategory::query()->count())->toBe(1);
});

it('refuses to delete an item used in proposals and allows deactivation instead', function () {
    $manager = catalogUser('manager');
    $item = (new CreateCatalogItemAction)->execute($manager, ['name' => 'Работа', 'price' => '100.00']);

    $proposal = (new CreateDraftProposalAction)->execute($manager);
    (new SaveDraftAction)->execute($proposal, [
        'title' => 'КП',
        'items' => [[
            'catalog_item_id' => $item->id,
            'name' => $item->name,
            'quantity_min' => '1',
            'quantity_max' => '2',
            'unit_price' => $item->price,
        ]],
        'sections' => [],
        'lock_version' => 1,
    ]);

    $this->actingAs($manager)
        ->delete(route('catalog.items.destroy', $item))
        ->assertStatus(409);

    expect(fn () => $item->delete())->toThrow(QueryException::class);

    $this->actingAs($manager)
        ->put(route('catalog.items.update', $item), ['name' => $item->name, 'price' => $item->price, 'active' => false])
        ->assertRedirect();

    expect($item->refresh()->active)->toBeFalse();
});

it('searches only active items for the picker', function () {
    $manager = catalogUser('manager');
    CatalogItem::factory()->create(['name' => 'Активная настройка']);
    CatalogItem::factory()->inactive()->create(['name' => 'Отключённая настройка']);

    $this->get(route('catalog.items.search'))->assertRedirect(route('login'));

    $response = $this->actingAs($manager)
        ->getJson(route('catalog.items.search', ['q' => 'настройка']))
        ->assertOk();

    expect(collect($response->json('items'))->pluck('name')->all())
        ->toContain('Активная настройка')
        ->not->toContain('Отключённая настройка');
});

it('updates item price without touching saved proposal snapshots', function () {
    $manager = catalogUser('manager');
    $item = (new CreateCatalogItemAction)->execute($manager, ['name' => 'Работа', 'unit' => 'ч', 'price' => '100.00']);

    $proposal = (new CreateDraftProposalAction)->execute($manager);
    (new SaveDraftAction)->execute($proposal, [
        'title' => 'КП',
        'items' => [[
            'catalog_item_id' => $item->id,
            'name' => $item->name,
            'unit' => 'ч',
            'quantity_min' => '1',
            'quantity_max' => '1',
            'unit_price' => $item->price,
        ]],
        'sections' => [],
        'lock_version' => 1,
    ]);

    (new UpdateCatalogItemAction)->execute($manager, $item, ['name' => 'Работа (новая)', 'unit' => 'ч', 'price' => '200.00']);

    $snapshotItem = $proposal->draftVersion->items()->sole();
    expect($snapshotItem->name)->toBe('Работа')
        ->and($snapshotItem->unit_price)->toBe('100.00');
});

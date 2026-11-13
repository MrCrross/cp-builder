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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

function pngUpload(string $append = ''): UploadedFile
{
    // Реальный 1x1 PNG: mime-валидация смотрит на содержимое, а не на расширение
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    $path = tempnam(sys_get_temp_dir(), 'img');
    file_put_contents($path, $png.$append);

    return new UploadedFile($path, 'pixel.png', 'image/png', null, true);
}

it('uploads, replaces and removes item image', function () {
    Storage::fake('local');
    $manager = catalogUser('manager');

    $this->actingAs($manager)->post(route('catalog.items.store'), [
        'name' => 'Позиция с фото',
        'price' => '10.00',
        'image' => pngUpload(),
    ])->assertRedirect();

    $item = CatalogItem::query()->sole();
    expect($item->image)->toMatch('#^catalog/[a-f0-9]{64}\.png$#')
        ->and(Storage::disk('local')->exists($item->image))->toBeTrue();

    $this->actingAs($manager)
        ->get(route('catalog.items.image', ['path' => $item->image]))
        ->assertOk();

    // Замена: прежний файл catalog/ удаляется
    $oldPath = $item->image;
    $this->actingAs($manager)->put(route('catalog.items.update', $item), [
        'name' => $item->name,
        'price' => $item->price,
        'image' => pngUpload('v2'),
    ])->assertRedirect();

    expect($item->refresh()->image)->not->toBe($oldPath)
        ->and(Storage::disk('local')->exists($oldPath))->toBeFalse();

    // Импортированная картинка (imports/) при удалении не трогается
    $importedPath = 'imports/commerceml/'.str_repeat('a', 64).'.jpg';
    Storage::disk('local')->put($importedPath, 'x');
    $item->update(['image' => $importedPath]);

    $this->actingAs($manager)->put(route('catalog.items.update', $item), [
        'name' => $item->name,
        'price' => $item->price,
        'remove_image' => true,
    ])->assertRedirect();

    expect($item->refresh()->image)->toBeNull()
        ->and(Storage::disk('local')->exists($importedPath))->toBeTrue();
});

it('rejects non-image upload and foreign image paths', function () {
    Storage::fake('local');
    $manager = catalogUser('manager');

    $this->actingAs($manager)->post(route('catalog.items.store'), [
        'name' => 'Не картинка',
        'price' => '10.00',
        'image' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
    ])->assertSessionHasErrors('image');

    $this->actingAs($manager)->get(route('catalog.items.image', ['path' => '../.env']))->assertNotFound();
    $this->actingAs($manager)->get(route('catalog.items.image', ['path' => 'catalog/../../secret']))->assertNotFound();
});

it('searches picker by item properties', function () {
    $manager = catalogUser('manager');
    CatalogItem::factory()->create([
        'name' => 'Задвижка 30с41нж',
        'properties' => [['name' => 'Диаметр', 'value' => 'ДН100'], ['name' => 'Материал', 'value' => 'чугун']],
    ]);
    CatalogItem::factory()->inactive()->create([
        'name' => 'Отключённая задвижка',
        'properties' => [['name' => 'Диаметр', 'value' => 'ДН100']],
    ]);

    $response = $this->actingAs($manager)
        ->getJson(route('catalog.items.search', ['q' => 'ДН100']))
        ->assertOk();

    $names = collect($response->json('items'))->pluck('name')->all();

    expect($names)->toContain('Задвижка 30с41нж')
        ->not->toContain('Отключённая задвижка')
        // Свойства отдаются в picker для отображения
        ->and($response->json('items.0.properties.0.value'))->toBe('ДН100');
});

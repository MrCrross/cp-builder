<?php

use App\Models\AuditEntry;
use App\Models\User;
use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\Catalog\Support\ImportedImageFetcher;
use App\Modules\Imports\Contracts\ImportedCatalogItem;
use App\Modules\Imports\Domain\Enums\ImportJobStatus;
use App\Modules\Imports\Models\ImportJob;
use Database\Seeders\BaselineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(BaselineSeeder::class);
    Storage::fake('local');
});

function importsUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findByName($role));

    return $user;
}

function importUpload(string $fixture, string $name): UploadedFile
{
    return new UploadedFile(
        __DIR__.'/../../Fixtures/imports/'.$fixture,
        $name,
        'text/xml',
        null,
        true,
    );
}

it('denies viewer and guests from imports', function () {
    $this->get(route('imports.index'))->assertRedirect(route('login'));

    $viewer = importsUser('viewer');

    $this->actingAs($viewer)->get(route('imports.index'))->assertForbidden();
    $this->actingAs($viewer)->post(route('imports.store'), [])->assertForbidden();
});

it('validates upload payload', function () {
    $manager = importsUser('manager');

    $this->actingAs($manager)
        ->post(route('imports.store'), ['profile' => 'unknown', 'files' => []])
        ->assertSessionHasErrors(['profile', 'files']);

    $notXml = UploadedFile::fake()->create('export.txt', 10, 'text/plain');

    $this->actingAs($manager)
        ->post(route('imports.store'), ['profile' => 'yml', 'files' => [$notXml]])
        ->assertSessionHasErrors(['files.0']);
});

it('runs the CommerceML pipeline end to end over sync queue', function () {
    $manager = importsUser('manager');

    // Картинки 1С скачиваются в storage: DNS-резолв подменяем (test-контейнер
    // не резолвит .test), HTTP — фейк.
    $this->app->instance(
        ImportedImageFetcher::class,
        new ImportedImageFetcher(fn (string $host): array => ['93.184.216.34']),
    );
    Http::fake(['files.example.test/*' => Http::response('fake-image-bytes')]);

    // upload (parse job отрабатывает синхронно: QUEUE_CONNECTION=sync)
    $response = $this->actingAs($manager)->post(route('imports.store'), [
        'profile' => 'commerceml',
        'files' => [
            importUpload('commerceml_classifier.xml', 'import_small.xml'),
            importUpload('commerceml_catalog.xml', 'import.xml'),
            importUpload('commerceml_prices.xml', 'prices.xml'),
        ],
    ]);

    $job = ImportJob::query()->sole();
    $response->assertRedirect(route('imports.show', $job));

    expect(ImportJobStatus::from($job->fresh()->status))
        ->toBe(ImportJobStatus::AwaitingConfirmation)
        ->and($job->rows_total)->toBe(4)
        ->and($job->rows_add)->toBe(2)   // 550501, 550503
        ->and($job->rows_skip)->toBe(2)  // 550502/550504 — delete несуществующих
        ->and($job->files()->count())->toBe(3)
        ->and($job->files()->pluck('detected_kind')->sort()->values()->all())
        ->toBe(['catalog', 'classifier', 'offers']);

    // quarantine очищен после разбора
    Storage::disk('local')
        ->assertMissing("imports/quarantine/{$job->id}");

    // preview: страница показывает строки и счётчики
    $this->actingAs($manager)
        ->get(route('imports.show', $job))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('imports/ImportShowPage')
            ->has('job.files', 3)
            ->has('rows.data', 4)
        );

    // confirm (apply job отрабатывает синхронно)
    $this->actingAs($manager)
        ->post(route('imports.confirm', $job))
        ->assertRedirect();

    $job->refresh();
    expect(ImportJobStatus::from($job->status))->toBe(ImportJobStatus::Completed)
        ->and($job->applied_created)->toBe(2)
        ->and($job->applied_updated)->toBe(0);

    $imageUrl = 'https://files.example.test/import/amort-001.jpg';
    $storagePath = 'imports/commerceml/'.hash('sha256', $imageUrl).'.jpg';

    $first = CatalogItem::query()
        ->where('source', 'commerceml')
        ->where('external_id', '11111111-2222-3333-4444-555555555501')
        ->sole();
    expect($first->name)->toBe('Амортизатор передний пример')
        ->and($first->price)->toBe('1250.50')
        ->and($first->unit)->toBe('шт')
        ->and($first->active)->toBeTrue()
        ->and($first->category_id)->toBeNull()
        ->and($first->properties)->toBe([
            ['name' => 'Бренд пример', 'value' => 'ПримерБренд'],
        ])
        ->and($first->image)->toBe($storagePath)
        ->and($first->source_url)->toBeNull();

    // Картинка реально скачана в storage
    Storage::disk('local')->assertExists($storagePath);

    // Относительный путь 1С хранится как есть (скачать неоткуда)
    $third = CatalogItem::query()
        ->where('source', 'commerceml')
        ->where('external_id', '11111111-2222-3333-4444-555555555503')
        ->sole();
    expect($third->price)->toBe('320.00')
        ->and($third->image)->toBe('import_files/pic-550503.jpg');

    // audit: upload + confirm + apply без секретов
    expect(AuditEntry::query()->whereIn('action', ['import.uploaded', 'import.confirmed', 'import.applied'])->count())
        ->toBe(3);
});

it('is idempotent: rerun of the same files changes nothing', function () {
    $manager = importsUser('manager');

    $upload = fn () => $this->actingAs($manager)->post(route('imports.store'), [
        'profile' => 'yml',
        'files' => [importUpload('yml_catalog.xml', 'yml_catalog.xml')],
    ]);

    $upload();
    $firstJob = ImportJob::query()->sole();
    $this->actingAs($manager)->post(route('imports.confirm', $firstJob));

    expect(CatalogItem::query()->where('source', 'yml')->count())->toBe(2);

    // YML: картинка — внешняя ссылка как есть, плюс ссылка на источник и характеристики
    $first = CatalogItem::query()->where('source', 'yml')->where('external_id', '1001')->sole();
    expect($first->image)->toBe('https://example.test/img/1001.jpg')
        ->and($first->source_url)->toBe('https://example.test/item/1001')
        ->and($first->properties)->toBe([
            ['name' => 'Водостойкость', 'value' => '0'],
            ['name' => 'Цвет', 'value' => 'серебристый'],
        ]);

    $upload();
    $secondJob = ImportJob::query()->latest('id')->first();
    expect($secondJob->rows_add)->toBe(0)
        ->and($secondJob->rows_skip)->toBe(2);

    $this->actingAs($manager)->post(route('imports.confirm', $secondJob));

    expect(CatalogItem::query()->where('source', 'yml')->count())->toBe(2)
        ->and($secondJob->fresh()->applied_created)->toBe(0)
        ->and($secondJob->fresh()->applied_updated)->toBe(0);
});

it('detects changes against existing catalog and applies updates', function () {
    $manager = importsUser('manager');

    $upload = fn () => $this->actingAs($manager)->post(route('imports.store'), [
        'profile' => 'yml',
        'files' => [importUpload('yml_catalog.xml', 'yml_catalog.xml')],
    ]);

    $upload();
    $this->actingAs($manager)->post(route('imports.confirm', ImportJob::query()->sole()));

    $item = CatalogItem::query()->where('source', 'yml')->where('external_id', '1001')->sole();
    $item->update(['price' => '1.00']);

    $upload();
    $job = ImportJob::query()->latest('id')->first();
    expect($job->rows_change)->toBe(1)
        ->and($job->rows_skip)->toBe(1);

    $this->actingAs($manager)->post(route('imports.confirm', $job));

    expect($item->fresh()->price)->toBe('12500.00')
        ->and($job->fresh()->applied_updated)->toBe(1);
});

it('deactivates catalog positions flagged as deleted in source', function () {
    $manager = importsUser('manager');

    CatalogItem::factory()->create([
        'source' => 'commerceml',
        'external_id' => '11111111-2222-3333-4444-555555555502',
        'name' => 'Свеча зажигания пример',
        'active' => true,
    ]);

    $this->actingAs($manager)->post(route('imports.store'), [
        'profile' => 'commerceml',
        'files' => [importUpload('commerceml_catalog.xml', 'import.xml')],
    ]);

    $job = ImportJob::query()->sole();
    expect($job->rows_delete)->toBe(1);

    $this->actingAs($manager)->post(route('imports.confirm', $job));

    expect(CatalogItem::query()
        ->where('source', 'commerceml')
        ->where('external_id', '11111111-2222-3333-4444-555555555502')
        ->sole()
        ->active)->toBeFalse();
});

it('rejects duplicate active upload of the same file', function () {
    $manager = importsUser('manager');

    $this->actingAs($manager)->post(route('imports.store'), [
        'profile' => 'commerceml',
        'files' => [importUpload('commerceml_catalog.xml', 'import.xml')],
    ])->assertSessionDoesntHaveErrors();

    $this->actingAs($manager)->post(route('imports.store'), [
        'profile' => 'commerceml',
        'files' => [importUpload('commerceml_catalog.xml', 'import-2.xml')],
    ])->assertStatus(409);
});

it('marks job failed with clear message on malformed file', function () {
    $manager = importsUser('manager');

    $this->actingAs($manager)->post(route('imports.store'), [
        'profile' => 'commerceml',
        'files' => [importUpload('malformed.xml', 'import.xml')],
    ]);

    $job = ImportJob::query()->sole();

    expect(ImportJobStatus::from($job->status))->toBe(ImportJobStatus::Failed)
        ->and($job->error_message)->toContain('import.xml');
});

it('cancels a pending import without touching the catalog', function () {
    $manager = importsUser('manager');

    $this->actingAs($manager)->post(route('imports.store'), [
        'profile' => 'yml',
        'files' => [importUpload('yml_catalog.xml', 'yml_catalog.xml')],
    ]);

    $job = ImportJob::query()->sole();

    $this->actingAs($manager)
        ->post(route('imports.cancel', $job))
        ->assertRedirect();

    expect(ImportJobStatus::from($job->fresh()->status))->toBe(ImportJobStatus::Cancelled)
        ->and(CatalogItem::query()->count())->toBe(0);
});

it('serves a downloadable error report for rows with errors', function () {
    $manager = importsUser('manager');

    $fixture = tempnam(sys_get_temp_dir(), 'import-row-error-');
    file_put_contents($fixture, '<?xml version="1.0" encoding="UTF-8"?>
<КоммерческаяИнформация xmlns="urn:1C.ru:commerceml_3" ВерсияСхемы="3.1" ДатаФормирования="2026-11-12T10:00:00" Ид="9">
  <ПакетПредложений СодержитТолькоИзменения="true">
    <Ид>offers-9</Ид>
    <Предложения>
      <Предложение>
        <Ид>no-price-item</Ид>
      </Предложение>
    </Предложения>
  </ПакетПредложений>
</КоммерческаяИнформация>');

    try {
        $this->actingAs($manager)->post(route('imports.store'), [
            'profile' => 'commerceml',
            'files' => [new UploadedFile($fixture, 'prices.xml', 'text/xml', null, true)],
        ]);
    } finally {
        unlink($fixture);
    }

    $job = ImportJob::query()->sole();
    expect($job->rows_error)->toBe(1);

    $this->actingAs($manager)
        ->get(route('imports.errors', $job))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

it('serves imported images from storage by safe paths only', function () {
    $manager = importsUser('manager');

    Storage::disk('local')->put('imports/commerceml/'.str_repeat('a', 64).'.jpg', 'fake-image-bytes');

    $this->actingAs($manager)
        ->get(route('catalog.items.image', ['path' => 'imports/commerceml/'.str_repeat('a', 64).'.jpg']))
        ->assertOk();

    // Траверсал и чужие пути — 404
    $this->actingAs($manager)
        ->get(route('catalog.items.image', ['path' => 'imports/commerceml/../../.env']))
        ->assertNotFound();

    $this->actingAs($manager)
        ->get(route('catalog.items.image', ['path' => 'app/secrets/key.pem']))
        ->assertNotFound();
});

it('blocks SSRF targets when downloading 1C images', function () {
    $fetcher = new ImportedImageFetcher(fn (string $host): array => ['127.0.0.1']);

    expect($fetcher->fetchToStorage('https://files.example.test/a.jpg', 'imports/commerceml/x.jpg'))->toBeNull();

    Storage::disk('local')->assertMissing('imports/commerceml/x.jpg');
});

it('normalizes commerceml image urls to deterministic storage paths', function () {
    $url = 'https://files.example.test/import/amort-001.jpg';

    expect(ImportedCatalogItem::normalizeImage('commerceml', $url))
        ->toBe('imports/commerceml/'.hash('sha256', $url).'.jpg')
        ->and(ImportedCatalogItem::normalizeImage('commerceml', 'import_files/pic.jpg'))
        ->toBe('import_files/pic.jpg')
        ->and(ImportedCatalogItem::normalizeImage('yml', $url))
        ->toBe($url)
        ->and(ImportedCatalogItem::normalizeImage('commerceml', null))
        ->toBeNull();
});

it('filters preview rows by action', function () {
    $manager = importsUser('manager');

    $this->actingAs($manager)->post(route('imports.store'), [
        'profile' => 'commerceml',
        'files' => [importUpload('commerceml_catalog.xml', 'import.xml')],
    ]);

    $job = ImportJob::query()->sole();

    // Без файла цен позиции неполные: action skip с row-ошибками, delete — только у существующих.
    expect($job->rows_skip)->toBe(3)->and($job->rows_error)->toBe(2);

    $this->actingAs($manager)
        ->get(route('imports.show', ['job' => $job, 'action' => 'skip']))
        ->assertInertia(fn ($page) => $page->has('rows.data', 3));

    $this->actingAs($manager)
        ->get(route('imports.show', ['job' => $job, 'errors' => '1']))
        ->assertInertia(fn ($page) => $page->has('rows.data', 2));
});

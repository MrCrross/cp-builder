<?php

use App\Models\AuditEntry;
use App\Models\User;
use App\Modules\CompanySettings\Infrastructure\Persistence\BrandingAsset;
use App\Modules\CompanySettings\Infrastructure\Persistence\OrganizationSettings;
use Database\Seeders\BaselineSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(BaselineSeeder::class));

function adminUser(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findByName('administrator'));

    return $user;
}

function managerUser(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findByName('manager'));

    return $user;
}

it('redirects guests from branding settings', function () {
    $this->get(route('settings.branding.edit'))->assertRedirect(route('login'));
});

it('forbids manager and viewer from branding endpoints', function (string $method, string $routeName) {
    $user = managerUser();

    $url = route($routeName, ['kind' => 'large_logo']);

    $this->actingAs($user)->call($method, $url)->assertForbidden();
})->with([
    ['GET', 'settings.branding.edit'],
    ['PUT', 'settings.branding.update'],
    ['POST', 'settings.branding.logo.store'],
    ['DELETE', 'settings.branding.logo.destroy'],
]);

it('shows branding page to administrator', function () {
    $this->actingAs(adminUser())
        ->get(route('settings.branding.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings-branding/BrandingSettingsPage'));
});

it('updates organization settings and writes audit', function () {
    $admin = adminUser();

    $this->actingAs($admin)
        ->put(route('settings.branding.update'), [
            'name' => 'ООО «Ромашка»',
            'legal_name' => 'Общество с ограниченной ответственностью «Ромашка»',
            'city' => 'Иркутск',
            'website' => 'https://romashka.example.test',
            'slogan' => 'Цветы для всех',
        ])
        ->assertRedirect();

    $settings = OrganizationSettings::current();

    expect($settings->name)->toBe('ООО «Ромашка»')
        ->and($settings->legal_name)->toContain('Ромашка')
        ->and($settings->city)->toBe('Иркутск')
        ->and($settings->website)->toBe('https://romashka.example.test')
        ->and($settings->slogan)->toBe('Цветы для всех');

    $audit = AuditEntry::query()->where('action', 'organization-settings.updated')->sole();

    expect($audit->actor_id)->toBe($admin->id)
        ->and($audit->after)->toMatchArray(['name' => 'ООО «Ромашка»', 'city' => 'Иркутск']);
});

it('validates settings input', function (array $payload, string $field) {
    adminUser();

    $this->actingAs(adminUser())->put(route('settings.branding.update'), $payload)
        ->assertSessionHasErrors($field);
})->with([
    [['name' => ''], 'name'],
    [['name' => 'A', 'website' => 'not-a-url'], 'website'],
    [['name' => 'A', 'website' => 'ftp://example.com'], 'website'],
]);

it('uploads a valid logo and writes audit', function () {
    Storage::fake('local');
    $admin = adminUser();

    $this->actingAs($admin)
        ->post(route('settings.branding.logo.store', ['kind' => 'large_logo']), [
            'logo' => UploadedFile::fake()->image('logo.png', 400, 200)->size(50),
        ])
        ->assertRedirect();

    $asset = BrandingAsset::query()->where('kind', 'large_logo')->sole();

    expect($asset->mime)->toBe('image/png')
        ->and($asset->width)->toBe(400)
        ->and($asset->height)->toBe(200)
        ->and($asset->path)->toStartWith('branding/large_logo-')
        ->and(Storage::disk('local')->exists($asset->path))->toBeTrue()
        // Приватность originals — свойство диска, а не файла (§9.4):
        // originals живут вне public-дерева и недоступны через Caddy.
        ->and(config('filesystems.disks.local.root'))->toBe(storage_path('app/private'));

    expect(AuditEntry::query()->where('action', 'branding.logo-uploaded')->exists())->toBeTrue();
});

it('rejects non-image uploads', function () {
    Storage::fake('local');

    $this->actingAs(adminUser())
        ->post(route('settings.branding.logo.store', ['kind' => 'large_logo']), [
            'logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
        ])
        ->assertSessionHasErrors('logo');

    expect(BrandingAsset::query()->count())->toBe(0);
});

it('rejects corrupt image content', function () {
    Storage::fake('local');

    $file = UploadedFile::fake()->create('logo.png', 10, 'image/png');

    $this->actingAs(adminUser())
        ->post(route('settings.branding.logo.store', ['kind' => 'large_logo']), ['logo' => $file])
        ->assertSessionHasErrors('logo');
});

it('rejects too small and too large logos', function () {
    Storage::fake('local');
    $admin = adminUser();

    $this->actingAs($admin)
        ->post(route('settings.branding.logo.store', ['kind' => 'small_logo']), [
            'logo' => UploadedFile::fake()->image('tiny.png', 16, 16),
        ])
        ->assertSessionHasErrors('logo');

    $this->actingAs($admin)
        ->post(route('settings.branding.logo.store', ['kind' => 'small_logo']), [
            'logo' => UploadedFile::fake()->image('huge.png', 3000, 100),
        ])
        ->assertSessionHasErrors('logo');

    expect(BrandingAsset::query()->count())->toBe(0);
});

it('accepts jpeg and webp logos', function (string $ext, string $mime) {
    Storage::fake('local');

    $this->actingAs(adminUser())
        ->post(route('settings.branding.logo.store', ['kind' => 'small_logo']), [
            'logo' => UploadedFile::fake()->image("logo.{$ext}", 100, 100),
        ])
        ->assertRedirect();

    expect(BrandingAsset::query()->where('kind', 'small_logo')->sole()->mime)->toBe($mime);
})->with([
    ['jpg', 'image/jpeg'],
    ['webp', 'image/webp'],
]);

it('replaces logo and removes the old file after commit', function () {
    Storage::fake('local');
    $admin = adminUser();

    $this->actingAs($admin)
        ->post(route('settings.branding.logo.store', ['kind' => 'large_logo']), [
            'logo' => UploadedFile::fake()->image('first.png', 100, 100),
        ]);

    $first = BrandingAsset::query()->sole()->path;

    $this->actingAs($admin)
        ->post(route('settings.branding.logo.store', ['kind' => 'large_logo']), [
            'logo' => UploadedFile::fake()->image('second.png', 120, 80),
        ])
        ->assertRedirect();

    expect(BrandingAsset::query()->count())->toBe(1)
        ->and(Storage::disk('local')->exists($first))->toBeFalse()
        ->and(Storage::disk('local')->exists(BrandingAsset::query()->sole()->path))->toBeTrue();
});

it('deletes logo with record, file and audit', function () {
    Storage::fake('local');
    $admin = adminUser();

    $this->actingAs($admin)
        ->post(route('settings.branding.logo.store', ['kind' => 'small_logo']), [
            'logo' => UploadedFile::fake()->image('logo.png', 100, 100),
        ]);

    $path = BrandingAsset::query()->sole()->path;

    $this->actingAs($admin)
        ->delete(route('settings.branding.logo.destroy', ['kind' => 'small_logo']))
        ->assertRedirect();

    expect(BrandingAsset::query()->count())->toBe(0)
        ->and(Storage::disk('local')->exists($path))->toBeFalse()
        ->and(AuditEntry::query()->where('action', 'branding.logo-deleted')->exists())->toBeTrue();
});

it('serves logo file to administrator only', function () {
    Storage::fake('local');

    $this->actingAs(adminUser())
        ->post(route('settings.branding.logo.store', ['kind' => 'large_logo']), [
            'logo' => UploadedFile::fake()->image('logo.png', 100, 100),
        ]);

    $this->actingAs(adminUser())
        ->get(route('settings.branding.logo', ['kind' => 'large_logo']))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    $this->actingAs(managerUser())
        ->get(route('settings.branding.logo', ['kind' => 'large_logo']))
        ->assertForbidden();
});

it('keeps organization settings a singleton', function () {
    OrganizationSettings::current();

    OrganizationSettings::query()->create(['id' => 2, 'name' => 'Второй']);
})->throws(QueryException::class);

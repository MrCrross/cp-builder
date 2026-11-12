<?php

use App\Models\User;
use App\Modules\CommercialProposals\Application\Actions\ArchiveProposalAction;
use App\Modules\CommercialProposals\Application\Actions\CreateDraftProposalAction;
use App\Modules\CommercialProposals\Application\Actions\IssueProposalAction;
use App\Modules\CommercialProposals\Application\Actions\RotatePublicLinkAction;
use App\Modules\CommercialProposals\Application\Actions\SaveDraftAction;
use App\Modules\CommercialProposals\Infrastructure\Persistence\PublicLinkAlias;
use App\Modules\CompanySettings\Infrastructure\Persistence\BrandingAsset;
use App\Modules\CompanySettings\Infrastructure\Persistence\OrganizationSettings;
use Database\Seeders\BaselineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(BaselineSeeder::class));

function manager(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findByName('manager'));

    return $user;
}

function issuedProposalWithToken(User $actor, array $payload): array
{
    $proposal = (new CreateDraftProposalAction)->execute($actor);
    (new SaveDraftAction)->execute($proposal, $payload);
    ['token' => $token] = (new IssueProposalAction)->execute($actor, $proposal);

    return [$proposal->refresh(), $token];
}

function publicPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'КП <script>alert("title")</script> для клиента',
        'client_name' => 'ООО «Клиент & Co»',
        'client_contact' => 'client@example.test',
        'manager_name' => 'Менеджер',
        'manager_email' => 'm@example.test',
        'items' => [[
            'name' => 'Работы <img src=x onerror=alert(1)>',
            'unit' => 'ч',
            'quantity_min' => '4',
            'quantity_max' => '8',
            'unit_price' => '1500.00',
        ]],
        'sections' => [[
            'type' => 'custom',
            'enabled' => true,
            'payload' => [
                'blocks' => [
                    // Inline-XSS внутри допустимого типа блока: проходит
                    // save-валидацию как строка, санитизируется рендерером.
                    ['type' => 'paragraph', 'data' => ['text' => 'Описание <script>alert(2)</script> и <img src=x onerror=alert(3)> работ']],
                ],
            ],
        ]],
        'lock_version' => 1,
    ], $overrides);
}

it('renders public document for valid token with escaped content', function () {
    [$proposal, $token] = issuedProposalWithToken(manager(), publicPayload());

    $response = $this->get(route('kp.show', ['token' => $token]));

    $response->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $html = $response->getContent();

    expect($html)
        ->toContain('КП &lt;script&gt;alert(&quot;title&quot;)&lt;/script&gt; для клиента')
        ->toContain('Работы &lt;img src=x onerror=alert(1)&gt;')
        ->toContain('Описание alert(2) и  работ')
        ->not->toContain('<script>')
        ->toContain($proposal->number)
        ->toContain('6'."\u{00A0}".'000,00') // amount_min: 4×1500, разряды — неразрывный пробел
        ->toContain('12'."\u{00A0}".'000,00') // amount_max: 8×1500 и итоги
        ->toContain('№');
});

it('hides drafts from public access', function () {
    $actor = manager();
    $proposal = (new CreateDraftProposalAction)->execute($actor);
    (new SaveDraftAction)->execute($proposal, publicPayload());

    // У draft нет link — подделываем revoked lookup невозможен; проверяем,
    // что случайный token не находит документ.
    $this->get(route('kp.show', ['token' => IssueProposalAction::generatePublicToken()]))
        ->assertNotFound();
});

it('hides archived proposals even with valid token', function () {
    $actor = manager();
    [$proposal, $token] = issuedProposalWithToken($actor, publicPayload());

    (new ArchiveProposalAction)->execute($actor, $proposal);

    $this->get(route('kp.show', ['token' => $token]))->assertNotFound();
});

it('rejects revoked tokens after rotation', function () {
    $actor = manager();
    [$proposal, $oldToken] = issuedProposalWithToken($actor, publicPayload());

    ['token' => $newToken] = (new RotatePublicLinkAction)->execute($actor, $proposal);

    $this->get(route('kp.show', ['token' => $oldToken]))->assertNotFound();
    $this->get(route('kp.show', ['token' => $newToken]))->assertOk();
});

it('serves logo from version snapshot', function () {
    Storage::fake('local');
    $actor = manager();

    $logoPath = 'branding/large_logo-test.png';
    $image = imagecreatetruecolor(80, 40);
    ob_start();
    imagepng($image);
    Storage::disk('local')->put($logoPath, ob_get_clean());
    imagedestroy($image);

    OrganizationSettings::current()
        ->fill(['name' => 'Организация'])
        ->save();

    BrandingAsset::query()->create([
        'kind' => 'large_logo',
        'disk' => 'local',
        'path' => $logoPath,
        'mime' => 'image/png',
        'bytes' => 100,
        'width' => 80,
        'height' => 40,
        'checksum' => hash('sha256', 'x'),
        'created_at' => now(),
    ]);

    [, $token] = issuedProposalWithToken($actor, publicPayload());

    $this->get(route('kp.logo', ['token' => $token, 'kind' => 'large_logo']))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    $this->get(route('kp.logo', ['token' => $token, 'kind' => 'small_logo']))
        ->assertNotFound();
});

it('renders legacy slug aliases without revealing canonical token', function () {
    [$proposal, $token] = issuedProposalWithToken(manager(), publicPayload());

    PublicLinkAlias::query()->create([
        'proposal_id' => $proposal->id,
        'legacy_slug' => 'old-slug-123',
        'created_at' => now(),
    ]);

    $response = $this->get(route('kp.slug', ['slug' => 'old-slug-123']));

    $response->assertOk();

    expect($response->getContent())->toContain($proposal->number)
        ->not->toContain($token);

    $this->get(route('kp.slug', ['slug' => 'unknown-slug']))->assertNotFound();
});

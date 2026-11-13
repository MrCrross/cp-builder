<?php

use App\Models\AuditEntry;
use App\Models\User;
use App\Modules\CommercialProposals\Actions\ArchiveProposalAction;
use App\Modules\CommercialProposals\Actions\CreateDraftProposalAction;
use App\Modules\CommercialProposals\Actions\CreateNewDraftFromIssuedAction;
use App\Modules\CommercialProposals\Actions\DeleteDraftProposalAction;
use App\Modules\CommercialProposals\Actions\IssueProposalAction;
use App\Modules\CommercialProposals\Actions\RestoreProposalAction;
use App\Modules\CommercialProposals\Actions\RotatePublicLinkAction;
use App\Modules\CommercialProposals\Actions\SaveDraftAction;
use App\Modules\CommercialProposals\Models\Proposal;
use App\Modules\CommercialProposals\Models\ProposalTemplate;
use App\Modules\CommercialProposals\Models\ProposalVersion;
use App\Modules\CommercialProposals\Models\PublicLink;
use App\Modules\CompanySettings\Models\BrandingAsset;
use App\Modules\CompanySettings\Models\OrganizationSettings;
use Database\Seeders\BaselineSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(BaselineSeeder::class));

function actorWithRole(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findByName($role));

    return $user;
}

function draftPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'КП на разработку',
        'subtitle' => null,
        'client_name' => 'ООО «Клиент»',
        'client_contact' => 'client@example.test',
        'manager_name' => 'Менеджер',
        'manager_email' => 'manager@example.test',
        'manager_phone' => null,
        'note' => null,
        'items' => [
            [
                'name' => 'Проектирование',
                'description' => null,
                'unit' => 'ч',
                'quantity_min' => '4',
                'quantity_max' => '8',
                'unit_price' => '1500.00',
            ],
            [
                'name' => 'Вёрстка',
                'unit' => 'ч',
                'quantity_min' => '2.5',
                'quantity_max' => '2.5',
                'unit_price' => '1200.00',
            ],
        ],
        'sections' => [
            [
                'type' => 'stages',
                'enabled' => true,
                'payload' => [
                    'title' => 'Этапы',
                    'stages' => [
                        ['title' => 'Проектирование', 'duration' => '1 неделя'],
                        ['title' => 'Разработка', 'duration' => '3 недели'],
                    ],
                ],
            ],
        ],
        'lock_version' => 1,
    ], $overrides);
}

it('creates a draft proposal without public access', function () {
    $actor = actorWithRole('manager');

    $proposal = (new CreateDraftProposalAction)->execute($actor);

    expect($proposal->status->value)->toBe('draft')
        ->and($proposal->owner_id)->toBe($actor->id)
        ->and($proposal->draftOnBranch())->not->toBeNull()
        ->and($proposal->draftOnBranch()->version)->toBe(1)
        ->and($proposal->publicLinks()->count())->toBe(0)
        ->and($proposal->number)->toBeNull();

    expect(AuditEntry::query()->where('action', 'proposal.created')->exists())->toBeTrue();
});

it('creates a draft from template copying content', function () {
    $actor = actorWithRole('manager');

    $template = ProposalTemplate::factory()->create([
        'default_content' => [
            'items' => [[
                'name' => 'Типовая работа',
                'unit' => 'ч',
                'quantity_min' => '1.00',
                'quantity_max' => '2.00',
                'unit_price' => '500.00',
                'amount_min' => '500.00',
                'amount_max' => '1000.00',
            ]],
            'sections' => [[
                'type' => 'price_badge',
                'enabled' => true,
                'payload' => ['label' => 'Стоимость по запросу'],
            ]],
        ],
    ]);

    $proposal = (new CreateDraftProposalAction)->execute($actor, $template->id);
    $version = $proposal->draftOnBranch();

    expect($version->title)->toBe($template->default_title)
        ->and($version->items()->count())->toBe(1)
        ->and($version->sections()->count())->toBe(1);
});

it('saves draft recalculating amounts and totals server-side', function () {
    $actor = actorWithRole('manager');
    $proposal = (new CreateDraftProposalAction)->execute($actor);
    $version = $proposal->draftOnBranch();

    $saved = (new SaveDraftAction)->execute($proposal, draftPayload());

    expect($saved->lock_version)->toBe(2);

    $items = $saved->items()->get();

    // 4..8 ч × 1500 = 6000..12000
    expect($items[0]->amount_min)->toBe('6000.00')
        ->and($items[0]->amount_max)->toBe('12000.00')
        // 2.5 × 1200 = 3000 (ровно)
        ->and($items[1]->amount_min)->toBe('3000.00')
        ->and($items[1]->amount_max)->toBe('3000.00');

    expect($saved->total_min)->toBe('9000.00')
        ->and($saved->total_max)->toBe('15000.00');
});

it('rounds line amounts half-up per line', function () {
    $actor = actorWithRole('manager');
    $proposal = (new CreateDraftProposalAction)->execute($actor);

    $saved = (new SaveDraftAction)->execute($proposal, draftPayload([
        'items' => [[
            'name' => 'Мелкая работа',
            'unit' => 'ч',
            'quantity_min' => '0.1',
            'quantity_max' => '0.1',
            'unit_price' => '0.29',
        ]],
    ]));

    expect($saved->items()->sole()->amount_min)->toBe('0.03')
        ->and($saved->total_min)->toBe('0.03');
});

it('rejects inconsistent quantities and unknown section types', function () {
    $actor = actorWithRole('manager');
    $proposal = (new CreateDraftProposalAction)->execute($actor);

    (new SaveDraftAction)->execute($proposal, draftPayload([
        'items' => [[
            'name' => 'Работа',
            'quantity_min' => '10',
            'quantity_max' => '5',
            'unit_price' => '100.00',
        ]],
    ]));
})->throws(UnprocessableEntityHttpException::class);

it('rejects saving with stale lock version', function () {
    $actor = actorWithRole('manager');
    $proposal = (new CreateDraftProposalAction)->execute($actor);

    (new SaveDraftAction)->execute($proposal, draftPayload());

    (new SaveDraftAction)->execute($proposal, draftPayload(['lock_version' => 1]));
})->throws(ConflictHttpException::class);

it('issues a draft atomically with number and stored public token', function () {
    $actor = actorWithRole('manager');
    $proposal = (new CreateDraftProposalAction)->execute($actor);
    (new SaveDraftAction)->execute($proposal, draftPayload());

    ['token' => $token] = (new IssueProposalAction)->execute($actor, $proposal);

    $proposal->refresh();

    expect($proposal->status->value)->toBe('issued')
        ->and($proposal->number)->toMatch('/^KP-\d{4}-\d{5}$/')
        ->and($proposal->year)->toBe((int) now()->format('Y'));

    expect($token)->not->toBeNull()->toBeString();

    $link = $proposal->publicLinks()->sole();

    expect($link->token)->toBe($token) // полный токен хранится: ссылка доступна всегда
        ->and($link->token_hash)->toBe(hash('sha256', $token)) // lookup — по hash
        ->and(strlen($token))->toBeGreaterThanOrEqual(43); // ≥256-bit base64url

    $version = $proposal->mainVersion();

    expect($version)->not->toBeNull()
        ->and($version->state->value)->toBe('issued')
        ->and($version->issued_at)->not->toBeNull();

    expect(AuditEntry::query()->where('action', 'proposal.issued')->exists())->toBeTrue();
});

it('numbers proposals sequentially per year', function () {
    $actor = actorWithRole('manager');

    $first = (new CreateDraftProposalAction)->execute($actor);
    (new SaveDraftAction)->execute($first, draftPayload());
    (new IssueProposalAction)->execute($actor, $first);

    $second = (new CreateDraftProposalAction)->execute($actor);
    (new SaveDraftAction)->execute($second, draftPayload());
    (new IssueProposalAction)->execute($actor, $second);

    expect((int) substr($second->refresh()->number, -5))
        ->toBe((int) substr($first->refresh()->number, -5) + 1);
});

it('forbids reissue without changes but allows after new draft', function () {
    $actor = actorWithRole('manager');
    $proposal = (new CreateDraftProposalAction)->execute($actor);
    (new SaveDraftAction)->execute($proposal, draftPayload());
    (new IssueProposalAction)->execute($actor, $proposal);

    // Повторный выпуск без нового черновика: draft отсутствует.
    (new IssueProposalAction)->execute($actor, $proposal->refresh());
})->throws(ModelNotFoundException::class);

it('supersedes previous issued version on next issue', function () {
    $actor = actorWithRole('manager');
    $proposal = (new CreateDraftProposalAction)->execute($actor);
    (new SaveDraftAction)->execute($proposal, draftPayload());
    (new IssueProposalAction)->execute($actor, $proposal);

    $v1 = $proposal->mainVersion();

    (new CreateNewDraftFromIssuedAction)->execute($actor, $proposal->refresh());
    (new SaveDraftAction)->execute($proposal->refresh(), draftPayload([
        'title' => 'КП на разработку v2',
    ]));

    // Публичная страница ещё старая.
    expect($proposal->refresh()->mainVersion()->title)->toBe('КП на разработку');

    (new IssueProposalAction)->execute($actor, $proposal->refresh());

    $proposal->refresh();

    expect($proposal->mainVersion()->version)->toBe(2)
        ->and($proposal->mainVersion()->title)->toBe('КП на разработку v2')
        ->and($v1->refresh()->state->value)->toBe('superseded');
});

it('keeps public document unchanged until next issue', function () {
    $actor = actorWithRole('manager');
    $proposal = (new CreateDraftProposalAction)->execute($actor);
    (new SaveDraftAction)->execute($proposal, draftPayload());
    (new IssueProposalAction)->execute($actor, $proposal);

    $issuedContent = $proposal->refresh()->mainVersion()->title;

    (new CreateNewDraftFromIssuedAction)->execute($actor, $proposal->refresh());
    (new SaveDraftAction)->execute($proposal->refresh(), draftPayload(['title' => 'Полностью новое КП']));

    expect($proposal->refresh()->mainVersion()->title)->toBe($issuedContent)
        ->and($proposal->mainVersion()->items()->count())->toBe(2)
        ->and($proposal->draftOnBranch()->items()->count())->toBe(2);
});

it('rotates public link revoking the old token', function () {
    $actor = actorWithRole('manager');
    $proposal = (new CreateDraftProposalAction)->execute($actor);
    (new SaveDraftAction)->execute($proposal, draftPayload());
    ['token' => $oldToken] = (new IssueProposalAction)->execute($actor, $proposal);

    ['token' => $newToken] = (new RotatePublicLinkAction)->execute($actor, $proposal->refresh());

    expect($newToken)->not->toBe($oldToken);

    $links = $proposal->publicLinks()->orderBy('id')->get();

    expect($links)->toHaveCount(2)
        ->and($links[0]->revoked_at)->not->toBeNull()
        ->and($links[0]->token)->toBe($oldToken) // отозванная строка хранит свой токен
        ->and($links[1]->revoked_at)->toBeNull()
        ->and($links[1]->token)->toBe($newToken)
        ->and(PublicLink::query()->where('token_hash', hash('sha256', $oldToken))->sole()->revoked_at)->not->toBeNull();

    expect(AuditEntry::query()->where('action', 'proposal.link-rotated')->exists())->toBeTrue();
});

it('allows a single active public link per proposal', function () {
    $actor = actorWithRole('manager');
    $proposal = (new CreateDraftProposalAction)->execute($actor);
    (new SaveDraftAction)->execute($proposal, draftPayload());
    (new IssueProposalAction)->execute($actor, $proposal);

    // Прямая вставка второго активного link нарушает partial unique.
    $proposal->publicLinks()->create([
        'token_hash' => hash('sha256', 'another-token'),
        'created_at' => now(),
    ]);
})->throws(QueryException::class);

it('enforces a single draft version per proposal', function () {
    $actor = actorWithRole('manager');
    $proposal = (new CreateDraftProposalAction)->execute($actor);

    ProposalVersion::factory()->create([
        'proposal_id' => $proposal->id,
        'version' => 2,
        'state' => 'draft',
    ]);
})->throws(QueryException::class);

it('archives and restores issued proposals only', function () {
    $actor = actorWithRole('manager');
    $proposal = (new CreateDraftProposalAction)->execute($actor);

    (new ArchiveProposalAction)->execute($actor, $proposal);
})->throws(ConflictHttpException::class);

it('archives issued and blocks issue while archived', function () {
    $actor = actorWithRole('manager');
    $proposal = (new CreateDraftProposalAction)->execute($actor);
    (new SaveDraftAction)->execute($proposal, draftPayload());
    (new IssueProposalAction)->execute($actor, $proposal);

    (new ArchiveProposalAction)->execute($actor, $proposal->refresh());

    expect($proposal->refresh()->status->value)->toBe('archived')
        ->and($proposal->archived_at)->not->toBeNull();

    (new CreateNewDraftFromIssuedAction)->execute($actor, $proposal->refresh());
})->throws(ConflictHttpException::class);

it('restores archived proposal', function () {
    $actor = actorWithRole('manager');
    $proposal = (new CreateDraftProposalAction)->execute($actor);
    (new SaveDraftAction)->execute($proposal, draftPayload());
    (new IssueProposalAction)->execute($actor, $proposal);
    (new ArchiveProposalAction)->execute($actor, $proposal->refresh());

    (new RestoreProposalAction)->execute($actor, $proposal->refresh());

    expect($proposal->refresh()->status->value)->toBe('issued')
        ->and($proposal->archived_at)->toBeNull();

    expect(AuditEntry::query()->where('action', 'proposal.archived')->exists())->toBeTrue()
        ->and(AuditEntry::query()->where('action', 'proposal.restored')->exists())->toBeTrue();
});

it('deletes only never-issued drafts', function () {
    $actor = actorWithRole('manager');
    $proposal = (new CreateDraftProposalAction)->execute($actor);

    (new DeleteDraftProposalAction)->execute($actor, $proposal);

    expect(Proposal::query()->whereKey($proposal->id)->exists())->toBeFalse()
        ->and(ProposalVersion::query()->count())->toBe(0);
});

it('refuses to delete issued proposal', function () {
    $actor = actorWithRole('manager');
    $proposal = (new CreateDraftProposalAction)->execute($actor);
    (new SaveDraftAction)->execute($proposal, draftPayload());
    (new IssueProposalAction)->execute($actor, $proposal);

    (new DeleteDraftProposalAction)->execute($actor, $proposal->refresh());
})->throws(ConflictHttpException::class);

it('copies logo files into version snapshot at issue', function () {
    Storage::fake('local');
    $actor = actorWithRole('manager');

    // Текущий бренд с логотипом.
    $settings = OrganizationSettings::current();
    $settings->fill(['name' => 'ООО «Ромашка»'])->save();

    $logoPath = 'branding/large_logo-testlogo.png';
    $image = imagecreatetruecolor(100, 50);
    ob_start();
    imagepng($image);
    Storage::disk('local')->put($logoPath, ob_get_clean());
    imagedestroy($image);

    BrandingAsset::query()->create([
        'kind' => 'large_logo',
        'disk' => 'local',
        'path' => $logoPath,
        'mime' => 'image/png',
        'bytes' => 100,
        'width' => 100,
        'height' => 50,
        'checksum' => hash('sha256', 'test'),
        'created_at' => now(),
    ]);

    $proposal = (new CreateDraftProposalAction)->execute($actor);
    (new SaveDraftAction)->execute($proposal, draftPayload());
    (new IssueProposalAction)->execute($actor, $proposal);

    $snapshot = $proposal->refresh()->mainVersion()->branding_snapshot;
    $snapPath = $snapshot['large_logo']['path'];

    expect($snapPath)->toStartWith("proposals/{$proposal->id}/v1/")
        ->and(Storage::disk('local')->exists($snapPath))->toBeTrue();

    // Замена бренда не меняет файл выпущенного документа.
    Storage::disk('local')->delete($logoPath);

    expect(Storage::disk('local')->exists($snapPath))->toBeTrue();
});

it('checks proposal permissions per role', function (string $role, string $ability, bool $expected) {
    $user = actorWithRole($role);
    $proposal = Proposal::factory()->create();

    expect($user->can($ability, $proposal))->toBe($expected);
})->with([
    ['viewer', 'view', true],
    ['viewer', 'create', false],
    ['viewer', 'update', false],
    ['viewer', 'issue', false],
    ['viewer', 'delete', false],
    ['viewer', 'archive', false],
    ['manager', 'view', true],
    ['manager', 'create', true],
    ['manager', 'update', true],
    ['manager', 'issue', true],
    ['manager', 'delete', true],
    ['manager', 'archive', true],
    ['manager', 'rotateLink', true],
    ['administrator', 'issue', true],
]);

it('saves items as a section with linked rows and honors enabled flag in public render', function () {
    $actor = actorWithRole('manager');
    $proposal = (new CreateDraftProposalAction)->execute($actor);

    $version = (new SaveDraftAction)->execute($proposal, [
        'title' => 'КП',
        'items' => [],
        'sections' => [
            [
                'type' => 'items',
                'enabled' => true,
                'payload' => [
                    'title' => 'Позиции',
                    'items' => [[
                        'name' => 'Монтаж оборудования',
                        'unit' => 'ч',
                        'quantity_min' => '2',
                        'quantity_max' => '4',
                        'unit_price' => '500.00',
                    ]],
                ],
            ],
            [
                'type' => 'price_badge',
                'enabled' => true,
                'payload' => ['label' => 'Стоимость по запросу'],
            ],
        ],
        'lock_version' => 1,
    ]);

    $item = $version->items()->sole();
    $itemsSection = $version->sections()->where('type', 'items')->sole();

    expect($item->section_id)->toBe($itemsSection->id)
        ->and($item->amount_min)->toBe('1000.00')
        ->and($item->amount_max)->toBe('2000.00')
        ->and($version->total_min)->toBe('1000.00')
        ->and($version->total_max)->toBe('2000.00');

    ['token' => $token] = (new IssueProposalAction)->execute($actor, $proposal);
    $html = $this->get(route('kp.show', ['token' => $token]))->assertOk()->getContent();

    expect($html)->toContain('Монтаж оборудования')
        ->toContain('Итого');

    // Выключенный блок позиций не попадает в документ
    $proposal2 = (new CreateDraftProposalAction)->execute($actor);
    (new SaveDraftAction)->execute($proposal2, [
        'title' => 'КП без позиций',
        'items' => [],
        'sections' => [
            [
                'type' => 'items',
                'enabled' => false,
                'payload' => [
                    'title' => null,
                    'items' => [[
                        'name' => 'Скрытая работа',
                        'unit' => 'ч',
                        'quantity_min' => '1',
                        'quantity_max' => '1',
                        'unit_price' => '100.00',
                    ]],
                ],
            ],
        ],
        'lock_version' => 1,
    ]);

    ['token' => $token2] = (new IssueProposalAction)->execute($actor, $proposal2);
    $html2 = $this->get(route('kp.show', ['token' => $token2]))->assertOk()->getContent();

    expect($html2)->not->toContain('Скрытая работа');
});

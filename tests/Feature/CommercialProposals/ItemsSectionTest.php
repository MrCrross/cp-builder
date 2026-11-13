<?php

use App\Models\User;
use App\Modules\Catalog\Actions\CreateCatalogItemAction;
use App\Modules\Catalog\Actions\UpdateCatalogItemAction;
use App\Modules\CommercialProposals\Actions\CreateDraftProposalAction;
use App\Modules\CommercialProposals\Actions\IssueProposalAction;
use App\Modules\CommercialProposals\Actions\RefreshDraftItemsFromCatalogAction;
use App\Modules\CommercialProposals\Actions\SaveDraftAction;
use App\Modules\CommercialProposals\Models\ProposalTemplate;
use Database\Seeders\BaselineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(BaselineSeeder::class));

function itemsManager(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findByName('manager'));

    return $user;
}

/**
 * @param  list<array<string, mixed>>  $rows
 */
function itemsSectionPayload(array $rows, bool $enabled = true): array
{
    return [
        'title' => 'Позиции',
        'items' => $rows,
        '_enabled' => $enabled,
    ];
}

/**
 * @param  list<array<string, mixed>>  $itemBlocks
 * @return array<string, mixed>
 */
function itemsDraftPayload(User $actor, array $itemBlocks): array
{
    $proposal = (new CreateDraftProposalAction)->execute($actor);

    return ['proposal' => $proposal, 'payload' => [
        'title' => 'КП с блоками позиций',
        'sections' => array_map(fn (array $block): array => [
            'type' => 'items',
            'enabled' => $block['_enabled'],
            'payload' => [
                'title' => $block['title'],
                'items' => $block['items'],
            ],
        ], $itemBlocks),
        'lock_version' => 1,
    ]];
}

function itemRow(string $name, string $price, string $qtyMin = '1', string $qtyMax = '1'): array
{
    return [
        'name' => $name,
        'unit' => 'шт',
        'quantity_min' => $qtyMin,
        'quantity_max' => $qtyMax,
        'unit_price' => $price,
    ];
}

it('saves items section rows linked to the section and computes totals', function () {
    $actor = itemsManager();
    ['proposal' => $proposal, 'payload' => $payload] = itemsDraftPayload($actor, [
        itemsSectionPayload([itemRow('Монтаж', '1000.00', '2', '4'), itemRow('Наладка', '500.00')]),
    ]);

    $version = (new SaveDraftAction)->execute($proposal, $payload)->refresh();

    $section = $version->sections()->sole();
    expect($section->type->value)->toBe('items')
        ->and($section->payload['items'])->toHaveCount(2)
        ->and($version->items()->count())->toBe(2)
        ->and($version->items()->pluck('section_id')->unique()->all())->toBe([$section->id])
        ->and($version->total_min)->toBe('2500.00') // 2×1000 + 1×500
        ->and($version->total_max)->toBe('4500.00'); // 4×1000 + 1×500
});

it('excludes disabled items sections from totals but keeps their rows', function () {
    $actor = itemsManager();
    ['proposal' => $proposal, 'payload' => $payload] = itemsDraftPayload($actor, [
        itemsSectionPayload([itemRow('Монтаж', '1000.00')]),
        itemsSectionPayload([itemRow('Опции', '500.00')], enabled: false),
    ]);

    $version = (new SaveDraftAction)->execute($proposal, $payload)->refresh();

    expect($version->items()->count())->toBe(2)
        ->and($version->total_min)->toBe('1000.00')
        ->and($version->total_max)->toBe('1000.00');
});

it('renders items sections as tables with subtotals and a document total', function () {
    $actor = itemsManager();
    ['proposal' => $proposal, 'payload' => $payload] = itemsDraftPayload($actor, [
        itemsSectionPayload([itemRow('Монтаж', '1000.00', '1', '2')]),
        itemsSectionPayload([itemRow('Опции', '500.00')]),
    ]);

    (new SaveDraftAction)->execute($proposal, $payload);
    ['token' => $token] = (new IssueProposalAction)->execute($actor, $proposal);

    $html = $this->get(route('kp.show', ['token' => $token]))->assertOk()->getContent();

    expect($html)
        ->toContain('Монтаж')
        ->toContain('Опции')
        ->toContain('Итого')
        ->toContain('Итого по документу')
        ->toContain('2'."\u{00A0}".'500,00'); // 1000–2000 + 500
});

it('does not show a document total for a single items section', function () {
    $actor = itemsManager();
    ['proposal' => $proposal, 'payload' => $payload] = itemsDraftPayload($actor, [
        itemsSectionPayload([itemRow('Монтаж', '1000.00')]),
    ]);

    (new SaveDraftAction)->execute($proposal, $payload);
    ['token' => $token] = (new IssueProposalAction)->execute($actor, $proposal);

    $html = $this->get(route('kp.show', ['token' => $token]))->assertOk()->getContent();

    expect($html)->not->toContain('Итого по документу');
});

it('validates item rows inside a section with section-scoped errors', function () {
    $actor = itemsManager();
    ['proposal' => $proposal, 'payload' => $payload] = itemsDraftPayload($actor, [
        itemsSectionPayload([['name' => '', 'quantity_min' => '1', 'quantity_max' => '1', 'unit_price' => '0.00']]),
    ]);

    (new SaveDraftAction)->execute($proposal, $payload);
})->throws(ValidationException::class);

it('refreshes catalog-linked rows inside items sections syncing payload and enabled-only totals', function () {
    $actor = itemsManager();
    $item = (new CreateCatalogItemAction)->execute($actor, ['name' => 'Работа А', 'unit' => 'ч', 'price' => '100.00']);

    $row = itemRow('Работа А', '100.00');
    $row['catalog_item_id'] = $item->id;
    $row['unit'] = 'ч';

    ['proposal' => $proposal, 'payload' => $payload] = itemsDraftPayload($actor, [
        itemsSectionPayload([$row]),
        itemsSectionPayload([itemRow('Опции', '500.00')], enabled: false),
    ]);

    (new SaveDraftAction)->execute($proposal, $payload);

    (new UpdateCatalogItemAction)->execute($actor, $item, ['name' => 'Работа Б', 'unit' => 'ч', 'price' => '250.00']);

    ['updated' => $updated] = app(RefreshDraftItemsFromCatalogAction::class)->execute($actor, $proposal);

    $version = $proposal->draftOnBranch()->refresh();
    $section = $version->sections()->where('enabled', true)->sole();

    expect($updated)->toBe(1)
        ->and($section->payload['items'][0]['name'])->toBe('Работа Б')
        ->and($section->payload['items'][0]['unit_price'])->toBe('250.00')
        ->and($version->total_min)->toBe('250.00')
        ->and($version->total_max)->toBe('250.00');
});

it('creates snapshot rows from template items sections', function () {
    $actor = itemsManager();

    $template = ProposalTemplate::factory()->create([
        'default_content' => [
            'items' => [],
            'sections' => [[
                'type' => 'items',
                'enabled' => true,
                'payload' => [
                    'title' => 'Позиции',
                    'items' => [itemRow('Из шаблона', '700.00', '2', '3')],
                ],
            ]],
        ],
    ]);

    $proposal = (new CreateDraftProposalAction)->execute($actor, $template->id);

    $version = $proposal->draftOnBranch();

    expect($version->items()->count())->toBe(1)
        ->and($version->items()->sole()->amount_min)->toBe('1400.00')
        ->and($version->items()->sole()->amount_max)->toBe('2100.00')
        ->and($version->items()->sole()->section_id)->not->toBeNull();
});

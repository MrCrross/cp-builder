<?php

use App\Models\User;
use App\Modules\CommercialProposals\Actions\ArchiveProposalAction;
use App\Modules\CommercialProposals\Actions\CreateDraftProposalAction;
use App\Modules\CommercialProposals\Actions\IssueProposalAction;
use App\Modules\CommercialProposals\Actions\SaveDraftAction;
use App\Modules\Statistics\Actions\RecordProposalVisitAction;
use App\Modules\Statistics\Models\StatisticsDailyDimension;
use App\Modules\Statistics\Models\StatisticsDailyProposal;
use App\Modules\Statistics\Models\StatisticsDailyUnique;
use App\Modules\Statistics\Models\StatisticsMeta;
use App\Modules\Statistics\Models\StatisticsVisit;
use Database\Seeders\BaselineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(BaselineSeeder::class));

function statsManager(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findByName('manager'));

    return $user;
}

function statsDraftPayload(): array
{
    return [
        'title' => 'КП для статистики',
        'client_name' => 'ООО «Тест Клиент»',
        'client_contact' => 'client@example.test',
        'manager_name' => 'Менеджер',
        'manager_email' => 'm@example.test',
        'items' => [[
            'name' => 'Работы',
            'unit' => 'ч',
            'quantity_min' => '1',
            'quantity_max' => '2',
            'unit_price' => '1000.00',
        ]],
        'sections' => [],
        'lock_version' => 1,
    ];
}

function statsIssuedProposal(User $actor): array
{
    $proposal = (new CreateDraftProposalAction)->execute($actor);
    (new SaveDraftAction)->execute($proposal, statsDraftPayload());
    ['token' => $token] = (new IssueProposalAction)->execute($actor, $proposal);

    return [$proposal->refresh(), $token];
}

it('records a visit with daily aggregates on public render', function () {
    [, $token] = statsIssuedProposal(statsManager());

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0')
        ->get(route('kp.show', ['token' => $token]))
        ->assertOk();

    $daily = StatisticsDailyProposal::query()->sole();

    expect($daily->visits)->toBe(1)
        ->and($daily->unique_visitors)->toBe(1)
        ->and($daily->bot_requests)->toBe(0)
        ->and($daily->date->toDateString())->toBe(Carbon::today()->toDateString());

    expect(StatisticsDailyUnique::query()->count())->toBe(1);

    $dimensions = StatisticsDailyDimension::query()->orderBy('dimension')->pluck('visits', 'dimension');
    expect($dimensions['device_type'])->toBe(1)
        ->and($dimensions['os'])->toBe(1);

    expect(StatisticsMeta::query()->sole()->last_visit_at)->not->toBeNull();
});

it('classifies bots into a separate counter without polluting visits', function () {
    [, $token] = statsIssuedProposal(statsManager());

    $this->withHeader('User-Agent', 'curl/8.5.0')
        ->get(route('kp.show', ['token' => $token]))
        ->assertOk();

    $daily = StatisticsDailyProposal::query()->sole();

    expect($daily->visits)->toBe(0)
        ->and($daily->unique_visitors)->toBe(0)
        ->and($daily->bot_requests)->toBe(1);

    expect(StatisticsDailyUnique::query()->count())->toBe(0)
        ->and(StatisticsDailyDimension::query()->count())->toBe(0);

    expect(StatisticsVisit::query()->sole()->is_bot)->toBeTrue();
});

it('deduplicates unique visitors within a day but counts repeat visits', function () {
    [, $token] = statsIssuedProposal(statsManager());
    $ua = ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0'];

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->withHeaders($ua)
        ->get(route('kp.show', ['token' => $token]));

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->withHeaders($ua)
        ->get(route('kp.show', ['token' => $token]));

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])->withHeaders($ua)
        ->get(route('kp.show', ['token' => $token]));

    $daily = StatisticsDailyProposal::query()->sole();

    expect($daily->visits)->toBe(3)
        ->and($daily->unique_visitors)->toBe(2);
});

it('keeps unique buckets separate per day', function () {
    [$proposal] = statsIssuedProposal(statsManager());

    Carbon::setTestNow(Carbon::today()->subDay()->startOfDay());
    (new RecordProposalVisitAction)->execute($proposal, '203.0.113.10', 'Chrome/120.0');
    Carbon::setTestNow(Carbon::today()->addDay()->startOfDay());
    (new RecordProposalVisitAction)->execute($proposal, '203.0.113.10', 'Chrome/120.0');
    Carbon::setTestNow();

    expect(StatisticsDailyProposal::query()->count())->toBe(2)
        ->and(StatisticsDailyProposal::query()->sum('unique_visitors'))->toBe(2);
});

it('does not track drafts, invalid tokens or archived proposals', function () {
    $actor = statsManager();
    $proposal = (new CreateDraftProposalAction)->execute($actor);
    (new SaveDraftAction)->execute($proposal, statsDraftPayload());

    // Случайный token — draft не находится публично.
    $this->get(route('kp.show', ['token' => IssueProposalAction::generatePublicToken()]))->assertNotFound();

    // Корректный цикл: issued трекается, после archive — 404 и новых записей нет.
    [$proposal, $token] = statsIssuedProposal($actor);
    $this->get(route('kp.show', ['token' => $token]))->assertOk();

    (new ArchiveProposalAction)->execute($actor, $proposal);
    $this->get(route('kp.show', ['token' => $token]))->assertNotFound();

    expect(StatisticsVisit::query()->count())->toBe(1)
        ->and(StatisticsDailyProposal::query()->sum('visits'))->toBe(1);
});

it('skips tracking when the module is disabled but still renders', function () {
    config()->set('statistics.enabled', false);

    [, $token] = statsIssuedProposal(statsManager());

    $this->get(route('kp.show', ['token' => $token]))->assertOk();

    expect(StatisticsVisit::query()->count())->toBe(0);
});

it('ignores tracking calls for non-issued proposals', function () {
    $proposal = (new CreateDraftProposalAction)->execute(statsManager());

    (new RecordProposalVisitAction)->execute($proposal, '203.0.113.10', 'Chrome/120.0');

    expect(StatisticsVisit::query()->count())->toBe(0);
});

it('does not track logo endpoint requests', function () {
    [, $token] = statsIssuedProposal(statsManager());

    $this->get(route('kp.show', ['token' => $token]))->assertOk();
    $this->get(route('kp.logo', ['token' => $token, 'kind' => 'large_logo']))->assertNotFound();

    expect(StatisticsVisit::query()->count())->toBe(1);
});

it('stores no PII columns in statistics tables', function () {
    foreach (['statistics_visits', 'statistics_daily_proposals', 'statistics_daily_uniques', 'statistics_daily_dimensions'] as $table) {
        expect(Schema::hasColumn($table, 'ip'))->toBeFalse()
            ->and(Schema::hasColumn($table, 'user_agent'))->toBeFalse();
    }
});

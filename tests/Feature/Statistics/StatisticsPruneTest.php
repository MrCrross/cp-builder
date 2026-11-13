<?php

use App\Models\User;
use App\Modules\CommercialProposals\Actions\CreateDraftProposalAction;
use App\Modules\CommercialProposals\Actions\IssueProposalAction;
use App\Modules\CommercialProposals\Actions\SaveDraftAction;
use App\Modules\Statistics\Actions\PruneStatisticsAction;
use App\Modules\Statistics\Actions\RecordProposalVisitAction;
use App\Modules\Statistics\Models\StatisticsDailyProposal;
use App\Modules\Statistics\Models\StatisticsDailyUnique;
use App\Modules\Statistics\Models\StatisticsVisit;
use Database\Seeders\BaselineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(BaselineSeeder::class));

it('prunes raw buffer beyond retention and keeps aggregates', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::findByName('manager'));

    $proposal = (new CreateDraftProposalAction)->execute($user);
    (new SaveDraftAction)->execute($proposal, [
        'title' => 'КП retention',
        'items' => [['name' => 'Работы', 'unit' => 'ч', 'quantity_min' => '1', 'quantity_max' => '1', 'unit_price' => '1000.00']],
        'sections' => [],
        'lock_version' => 1,
    ]);
    (new IssueProposalAction)->execute($user, $proposal);
    $proposal->refresh();

    $visit = new RecordProposalVisitAction;
    $realNow = Carbon::now();

    // Старое событие: raw должен сгореть, агрегаты остаться.
    Carbon::setTestNow($realNow->copy()->subDays(10));
    $visit->execute($proposal, '203.0.113.10', 'Chrome/120.0 Windows');

    // Свежее событие: ничего не трогаем.
    Carbon::setTestNow($realNow);
    $visit->execute($proposal, '203.0.113.11', 'Chrome/120.0 Windows');
    Carbon::setTestNow();

    $result = (new PruneStatisticsAction)->execute();

    expect($result['raw_deleted'])->toBe(1)
        ->and(StatisticsVisit::query()->count())->toBe(1);

    // Оба дня в агрегатах сохранились, свежий день не затронут.
    expect(StatisticsDailyProposal::query()->count())->toBe(2)
        ->and(StatisticsDailyProposal::query()->sum('visits'))->toBe(2)
        ->and(StatisticsDailyUnique::query()->count())->toBe(2);
});

it('prunes daily aggregates beyond the aggregates horizon', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::findByName('manager'));

    $proposal = (new CreateDraftProposalAction)->execute($user);
    (new SaveDraftAction)->execute($proposal, [
        'title' => 'КП retention',
        'items' => [['name' => 'Работы', 'unit' => 'ч', 'quantity_min' => '1', 'quantity_max' => '1', 'unit_price' => '1000.00']],
        'sections' => [],
        'lock_version' => 1,
    ]);
    (new IssueProposalAction)->execute($user, $proposal);
    $proposal->refresh();

    config()->set('statistics.aggregates_retention_days', 30);

    $visit = new RecordProposalVisitAction;
    $realNow = Carbon::now();

    Carbon::setTestNow($realNow->copy()->subDays(100));
    $visit->execute($proposal, '203.0.113.10', 'Chrome/120.0 Windows');

    Carbon::setTestNow($realNow);
    $visit->execute($proposal, '203.0.113.10', 'Chrome/120.0 Windows');
    Carbon::setTestNow();

    (new PruneStatisticsAction)->execute();

    $remaining = StatisticsDailyProposal::query()->pluck('date')->map(fn ($d) => $d->toDateString());

    expect($remaining)->toHaveCount(1)
        ->and($remaining->first())->toBe(Carbon::today()->toDateString());
});

it('runs the prune artisan command successfully', function () {
    $this->artisan('statistics:prune')->assertSuccessful();
});

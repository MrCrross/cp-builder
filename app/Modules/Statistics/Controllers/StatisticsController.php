<?php

namespace App\Modules\Statistics\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CommercialProposals\Models\Proposal;
use App\Modules\Statistics\Domain\Enums\DimensionType;
use App\Modules\Statistics\Domain\ValueObjects\StatisticsArea;
use App\Modules\Statistics\Models\StatisticsDailyDimension;
use App\Modules\Statistics\Models\StatisticsDailyProposal;
use App\Modules\Statistics\Models\StatisticsMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Дашборд статистики (план §13.5): чтение ТОЛЬКО из дневных агрегатов,
 * никаких обращений к raw buffer в запросах. Опциональный фильтр
 * ?proposal_id= переключает все метрики на одно КП.
 */
class StatisticsController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', StatisticsArea::class);

        $period = $this->resolvePeriod($request);
        $proposalId = $this->resolveProposalId($request);

        $from = now()->subDays($period - 1)->toDateString();

        $daily = StatisticsDailyProposal::query()->where('date', '>=', $from);

        if ($proposalId !== null) {
            $daily->where('proposal_id', $proposalId);
        }

        $totalsRow = (clone $daily)
            ->selectRaw(
                'COALESCE(SUM(visits), 0) AS visits, COALESCE(SUM(unique_visitors), 0) AS unique_visitors, COALESCE(SUM(bot_requests), 0) AS bot_requests',
            )->firstOrFail();

        $proposalsWithVisits = $proposalId === null
            ? (clone $daily)->where('visits', '>', 0)->distinct()->count('proposal_id')
            : ((int) $totalsRow->visits > 0 ? 1 : 0);

        return Inertia::render('statistics/StatisticsPage', [
            'totals' => [
                'visits' => (int) $totalsRow->visits,
                'unique_visitors' => (int) $totalsRow->unique_visitors,
                'bot_requests' => (int) $totalsRow->bot_requests,
                'proposals_with_visits' => $proposalsWithVisits,
            ],
            'series' => $this->buildSeries(clone $daily, $period),
            'breakdowns' => $this->buildBreakdowns($from, $proposalId),
            'proposals' => $this->buildProposalRows($from, $proposalId),
            'proposal_options' => $this->buildProposalOptions(),
            'filters' => [
                'period' => $period,
                'proposal_id' => $proposalId,
            ],
            'freshness' => [
                'last_visit_at' => StatisticsMeta::query()->value('last_visit_at'),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    private function resolvePeriod(Request $request): int
    {
        $period = $request->integer('period') ?: (int) config('statistics.default_period_days', 30);

        return in_array($period, config('statistics.allowed_periods', [7, 30, 90]), true)
            ? $period
            : (int) config('statistics.default_period_days', 30);
    }

    private function resolveProposalId(Request $request): ?int
    {
        $id = $request->integer('proposal_id') ?: null;

        if ($id === null) {
            return null;
        }

        return Proposal::query()->whereKey($id)->exists() ? $id : null;
    }

    /**
     * Дневной time series за период; пропуски заполняются нулями.
     *
     * @param  Builder<StatisticsDailyProposal>  $daily
     * @return array<int, array{date: string, visits: int, unique_visitors: int}>
     */
    private function buildSeries($daily, int $period): array
    {
        $byDate = $daily
            ->selectRaw('date, SUM(visits) AS visits, SUM(unique_visitors) AS unique_visitors')
            ->groupBy('date')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse((string) $row->date)->toDateString());

        $series = [];

        for ($i = $period - 1; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i)->toDateString();
            $row = $byDate->get($date);

            $series[] = [
                'date' => $date,
                'visits' => (int) ($row->visits ?? 0),
                'unique_visitors' => (int) ($row->unique_visitors ?? 0),
            ];
        }

        return $series;
    }

    /**
     * Разбивки visits по устройствам и ОС (системная — SUM-группировкой
     * по КП; ADR-0011).
     *
     * @return array<string, array<int, array{value: string, visits: int}>>
     */
    private function buildBreakdowns(string $from, ?int $proposalId): array
    {
        $query = StatisticsDailyDimension::query()
            ->where('date', '>=', $from)
            ->selectRaw('dimension, value, SUM(visits) AS visits')
            ->groupBy('dimension', 'value')
            ->orderByDesc('visits')
            ->limit(40);

        if ($proposalId !== null) {
            $query->where('proposal_id', $proposalId);
        }

        $breakdowns = [
            DimensionType::DeviceType->value => [],
            DimensionType::Os->value => [],
        ];

        foreach ($query->get() as $row) {
            if (! isset($breakdowns[$row->dimension])) {
                continue;
            }

            $breakdowns[$row->dimension][] = [
                'value' => $row->value,
                'visits' => (int) $row->visits,
            ];
        }

        return $breakdowns;
    }

    /**
     * Пер-КП таблица за период (top 50 по visits).
     *
     * @return array<int, array{id: int, number: string|null, title: string|null, visits: int, unique_visitors: int, bot_requests: int}>
     */
    private function buildProposalRows(string $from, ?int $proposalId): array
    {
        $query = StatisticsDailyProposal::query()
            ->where('date', '>=', $from)
            ->selectRaw('proposal_id, SUM(visits) AS visits, SUM(unique_visitors) AS unique_visitors, SUM(bot_requests) AS bot_requests')
            ->groupBy('proposal_id')
            ->orderByDesc('visits')
            ->limit(50);

        if ($proposalId !== null) {
            $query->where('proposal_id', $proposalId);
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $proposals = Proposal::query()
            ->whereIn('id', $rows->pluck('proposal_id'))
            ->with('issuedVersion:id,proposal_id,title')
            ->get()
            ->keyBy('id');

        return $rows->map(fn ($row): array => [
            'id' => (int) $row->proposal_id,
            'number' => $proposals[$row->proposal_id]?->number,
            'title' => $proposals[$row->proposal_id]?->issuedVersion?->title,
            'visits' => (int) $row->visits,
            'unique_visitors' => (int) $row->unique_visitors,
            'bot_requests' => (int) $row->bot_requests,
        ])->all();
    }

    /**
     * @return array<int, array{id: int, number: string|null, title: string|null}>
     */
    private function buildProposalOptions(): array
    {
        $ids = StatisticsDailyProposal::query()
            ->distinct()
            ->orderBy('proposal_id')
            ->limit(500)
            ->pluck('proposal_id');

        if ($ids->isEmpty()) {
            return [];
        }

        return Proposal::query()
            ->whereIn('id', $ids)
            ->with('issuedVersion:id,proposal_id,title')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Proposal $proposal): array => [
                'id' => $proposal->id,
                'number' => $proposal->number,
                'title' => $proposal->issuedVersion?->title,
            ])
            ->all();
    }
}

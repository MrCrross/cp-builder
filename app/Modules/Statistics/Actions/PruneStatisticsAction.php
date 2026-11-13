<?php

namespace App\Modules\Statistics\Actions;

use App\Modules\Statistics\Models\StatisticsDailyDimension;
use App\Modules\Statistics\Models\StatisticsDailyProposal;
use App\Modules\Statistics\Models\StatisticsDailyUnique;
use App\Modules\Statistics\Models\StatisticsVisit;
use Carbon\CarbonInterface;

/**
 * Retention статистики (ADR-0011): raw buffer живёт несколько дней,
 * дневные агрегаты — ограниченный горизонт. Запускается расписанием
 * (statistics:prune, daily) и вручную.
 */
class PruneStatisticsAction
{
    /**
     * @return array{raw_deleted: int, aggregates_deleted: int}
     */
    public function execute(?CarbonInterface $now = null): array
    {
        $now ??= now();

        $rawCutoff = $now->copy()->subDays((int) config('statistics.raw_retention_days', 3))->endOfDay();
        $aggregatesCutoff = $now->copy()->subDays((int) config('statistics.aggregates_retention_days', 730))->endOfDay();

        $rawDeleted = StatisticsVisit::query()
            ->where('visited_at', '<=', $rawCutoff)
            ->delete();

        $aggregatesDeleted = 0;

        foreach ([StatisticsDailyProposal::query(), StatisticsDailyUnique::query(), StatisticsDailyDimension::query()] as $query) {
            $aggregatesDeleted += $query->where('date', '<=', $aggregatesCutoff)->delete();
        }

        return [
            'raw_deleted' => $rawDeleted,
            'aggregates_deleted' => $aggregatesDeleted,
        ];
    }
}

<?php

namespace App\Modules\Statistics\Application\Actions;

use App\Modules\CommercialProposals\Domain\ProposalStatus;
use App\Modules\CommercialProposals\Infrastructure\Persistence\Proposal;
use App\Modules\Statistics\Domain\BotClassifier;
use App\Modules\Statistics\Domain\DeviceClassifier;
use App\Modules\Statistics\Domain\DimensionType;
use App\Modules\Statistics\Domain\OsClassifier;
use App\Modules\Statistics\Infrastructure\Persistence\StatisticsDailyDimension;
use App\Modules\Statistics\Infrastructure\Persistence\StatisticsDailyProposal;
use App\Modules\Statistics\Infrastructure\Persistence\StatisticsDailyUnique;
use App\Modules\Statistics\Infrastructure\Persistence\StatisticsMeta;
use App\Modules\Statistics\Infrastructure\Persistence\StatisticsVisit;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Фиксация просмотра публичного issued-документа (план §13.5, ADR-0011).
 * Всё PII обрабатывается только в памяти: классификация ботов/устройства/ОС
 * и вращающийся ежедневный salted visitor hash. В БД попадают только
 * агрегаты и необратимый hash. Запись синхронная — отчётность не зависит
 * от отложенных джобов; raw buffer сгорает по TTL (statistics:prune).
 */
class RecordProposalVisitAction
{
    public function execute(
        Proposal $proposal,
        ?string $ip,
        ?string $userAgent,
        ?CarbonInterface $visitedAt = null,
    ): void {
        if (! config('statistics.enabled', true)) {
            return;
        }

        if ($proposal->status !== ProposalStatus::Issued) {
            return; // инвариант: трекинг только issued (приёмка этапа 7)
        }

        $visitedAt ??= now();
        $date = $visitedAt->toDateString();
        $isBot = BotClassifier::isBot($userAgent);

        DB::transaction(function () use ($proposal, $ip, $userAgent, $visitedAt, $date, $isBot): void {
            StatisticsVisit::query()->create([
                'proposal_id' => $proposal->id,
                'visited_at' => $visitedAt,
                'is_bot' => $isBot,
                'device_type' => DeviceClassifier::detect($userAgent),
                'os' => OsClassifier::detect($userAgent),
                'visitor_hash' => $this->visitorHash($proposal, $date, $ip, $userAgent),
                'created_at' => $visitedAt,
            ]);

            $this->upsertDailyAggregate($proposal, $date, $isBot);

            if (! $isBot) {
                $this->registerUniqueVisitor($proposal, $date, $ip, $userAgent);
                $this->upsertDimensions($proposal, $date, $userAgent);
            }

            $meta = StatisticsMeta::query()->first() ?? new StatisticsMeta;
            $meta->last_visit_at = $visitedAt;
            $meta->save();
        });
    }

    /**
     * Вращающийся pseudonymous id (ADR-0011): соль содержит дату бакета,
     * поэтому hash нельзя склеить между днями, а IP/UA в БД не пишутся.
     */
    private function visitorHash(Proposal $proposal, string $date, ?string $ip, ?string $userAgent): string
    {
        $salt = hash('sha256', (string) config('app.key').'|statistics|'.$date);

        return hash_hmac('sha256', implode('|', [$date, (string) $proposal->id, $ip ?? '', $userAgent ?? '']), $salt);
    }

    private function upsertDailyAggregate(Proposal $proposal, string $date, bool $isBot): void
    {
        $now = now();

        StatisticsDailyProposal::query()->upsert(
            [[
                'proposal_id' => $proposal->id,
                'date' => $date,
                'visits' => $isBot ? 0 : 1,
                'unique_visitors' => 0,
                'bot_requests' => $isBot ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['proposal_id', 'date'],
            [
                'visits' => $isBot
                    ? DB::raw('statistics_daily_proposals.visits')
                    : DB::raw('statistics_daily_proposals.visits + 1'),
                'unique_visitors' => DB::raw('statistics_daily_proposals.unique_visitors'),
                'bot_requests' => $isBot
                    ? DB::raw('statistics_daily_proposals.bot_requests + 1')
                    : DB::raw('statistics_daily_proposals.bot_requests'),
                'updated_at' => $now,
            ],
        );
    }

    private function registerUniqueVisitor(Proposal $proposal, string $date, ?string $ip, ?string $userAgent): void
    {
        $inserted = StatisticsDailyUnique::query()->insertOrIgnore([[
            'proposal_id' => $proposal->id,
            'date' => $date,
            'visitor_hash' => $this->visitorHash($proposal, $date, $ip, $userAgent),
        ]]);

        if ($inserted > 0) {
            StatisticsDailyProposal::query()
                ->where('proposal_id', $proposal->id)
                ->where('date', $date)
                ->increment('unique_visitors');
        }
    }

    private function upsertDimensions(Proposal $proposal, string $date, ?string $userAgent): void
    {
        $now = now();

        foreach ([
            [DimensionType::DeviceType, DeviceClassifier::detect($userAgent)],
            [DimensionType::Os, OsClassifier::detect($userAgent)],
        ] as [$dimension, $value]) {
            StatisticsDailyDimension::query()->upsert(
                [[
                    'proposal_id' => $proposal->id,
                    'date' => $date,
                    'dimension' => $dimension->value,
                    'value' => $value,
                    'visits' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                ['proposal_id', 'date', 'dimension', 'value'],
                [
                    'visits' => DB::raw('statistics_daily_dimensions.visits + 1'),
                    'updated_at' => $now,
                ],
            );
        }
    }
}

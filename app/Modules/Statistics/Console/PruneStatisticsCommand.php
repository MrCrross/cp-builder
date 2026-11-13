<?php

namespace App\Modules\Statistics\Console;

use App\Modules\Statistics\Actions\PruneStatisticsAction;
use Illuminate\Console\Command;

class PruneStatisticsCommand extends Command
{
    protected $signature = 'statistics:prune';

    protected $description = 'Удалить просроченные raw-события и дневные агрегаты статистики (retention, ADR-0011)';

    public function handle(PruneStatisticsAction $action): int
    {
        $result = $action->execute();

        $this->info(sprintf(
            'Статистика очищена: raw buffer — %d строк, агрегаты — %d строк.',
            $result['raw_deleted'],
            $result['aggregates_deleted'],
        ));

        return self::SUCCESS;
    }
}

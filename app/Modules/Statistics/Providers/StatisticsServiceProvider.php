<?php

namespace App\Modules\Statistics\Providers;

use App\Modules\Statistics\Console\PruneStatisticsCommand;
use App\Modules\Statistics\Policies\StatisticsPolicy;
use App\Modules\Statistics\Support\StatisticsArea;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class StatisticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(StatisticsArea::class, StatisticsPolicy::class);

        $this->commands([
            PruneStatisticsCommand::class,
        ]);
    }
}

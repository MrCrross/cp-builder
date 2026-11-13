<?php

namespace App\Modules\Statistics\Policies;

use App\Models\User;
use App\Modules\Statistics\Domain\ValueObjects\StatisticsArea;

/**
 * Дашборд статистики: administrator и manager (permission statistics.view);
 * viewer исключён (ADR-0011, приёмка этапа 7).
 */
class StatisticsPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('statistics.view');
    }

    public function view(User $user, StatisticsArea $area): bool
    {
        return $user->can('statistics.view');
    }
}

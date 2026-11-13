<?php

namespace App\Modules\Statistics\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Синглтон свежести статистики: момент последнего зафиксированного
 * просмотра (индикатор свежести данных дашборда, план §13.5).
 */
class StatisticsMeta extends Model
{
    protected $table = 'statistics_meta';

    protected $fillable = ['last_visit_at'];

    protected function casts(): array
    {
        return [
            'last_visit_at' => 'datetime',
        ];
    }

    public static function snapshot(): self
    {
        return static::query()->firstOrCreate([], ['last_visit_at' => null]);
    }
}

<?php

/**
 * Модуль статистики (план §13.5, ADR-0011): только агрегаты без PII.
 */

return [
    // Мастер-выключатель трекинга. Отключение не ломает публичный рендер.
    'enabled' => env('STATISTICS_ENABLED', true),

    // Raw buffer: события хранятся ограниченное время и сгорают по TTL.
    'raw_retention_days' => (int) env('STATISTICS_RAW_RETENTION_DAYS', 3),

    // Дневные агрегаты.
    'aggregates_retention_days' => (int) env('STATISTICS_AGGREGATES_RETENTION_DAYS', 730),

    // Период дашборда по умолчанию и допустимые значения (дней).
    'default_period_days' => 30,
    'allowed_periods' => [7, 30, 90],
];

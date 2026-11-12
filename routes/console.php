<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retention статистики (ADR-0011): raw buffer — 3 дня, агрегаты — 730 дней.
Schedule::command('statistics:prune')->daily();

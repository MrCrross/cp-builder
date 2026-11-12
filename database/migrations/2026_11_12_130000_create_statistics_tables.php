<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Модуль статистики (план §13.5, ADR-0011): raw buffer с TTL + дневные
 * агрегаты. PII (IP/UA/deviceId) не хранится ни в одной таблице —
 * классификация ботов/устройств/ОС и вращающийся salted visitor hash
 * вычисляются при записи события.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statistics_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained('proposals')->cascadeOnDelete();
            $table->timestamp('visited_at');
            $table->boolean('is_bot');
            $table->string('device_type')->nullable();
            $table->string('os')->nullable();
            $table->char('visitor_hash', 64);
            $table->timestamp('created_at')->nullable();

            $table->index(['proposal_id', 'visited_at']);
            $table->index('visited_at');
        });

        Schema::create('statistics_daily_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained('proposals')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('visits')->default(0);
            $table->unsignedInteger('unique_visitors')->default(0);
            $table->unsignedInteger('bot_requests')->default(0);
            $table->timestamps();

            $table->unique(['proposal_id', 'date']);
            $table->index('date');
        });

        // Дедуп-сет дневных уников: одна строка на (КП, день, hash).
        // Счётчик unique_visitors в daily-строке инкрементится только при
        // реальной вставке (insertOrIgnore) — гонки записей не портят uniques.
        Schema::create('statistics_daily_uniques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained('proposals')->cascadeOnDelete();
            $table->date('date');
            $table->char('visitor_hash', 64);

            $table->unique(['proposal_id', 'date', 'visitor_hash'], 'statistics_uniques_unique');
            $table->index('date');
        });

        // Разбивки visits по измерениям (device_type/os) — на уровне КП;
        // системная разбивка дашборд считает SUM-группировкой.
        Schema::create('statistics_daily_dimensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained('proposals')->cascadeOnDelete();
            $table->date('date');
            $table->string('dimension');
            $table->string('value');
            $table->unsignedInteger('visits')->default(0);
            $table->timestamps();

            $table->unique(['proposal_id', 'date', 'dimension', 'value'], 'statistics_dimensions_unique');
            $table->index('date');
        });

        // Синглтон свежести данных: момент последнего зафиксированного просмотра.
        Schema::create('statistics_meta', function (Blueprint $table) {
            $table->id();
            $table->timestamp('last_visit_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statistics_meta');
        Schema::dropIfExists('statistics_daily_dimensions');
        Schema::dropIfExists('statistics_daily_uniques');
        Schema::dropIfExists('statistics_daily_proposals');
        Schema::dropIfExists('statistics_visits');
    }
};

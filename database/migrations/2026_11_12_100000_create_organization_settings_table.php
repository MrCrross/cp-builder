<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Настройки организации — singleton (план §8.2, §10.1): название, сайт,
 * слоган, юридическое имя, город. Только подтверждённые brand/legal поля.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('city')->nullable();
            $table->string('website')->nullable();
            $table->string('slogan')->nullable();
            $table->timestamps();
        });

        // CHECK применяется на PostgreSQL; sqlite ≥ 3.53 понимает синтаксис,
        // старше — пропускаем enforcement (см. .ai/rules/backend.md).
        try {
            DB::statement('alter table organization_settings add constraint organization_settings_single_row check (id = 1)');
        } catch (QueryException) {
            // SQLite < 3.53: ALTER TABLE ADD CONSTRAINT не поддерживается.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_settings');
    }
};

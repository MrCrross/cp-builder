<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Метаданные логотипов организации (план §8.2, §9.4): metadata, не BLOB.
 * kind=large_logo|small_logo — один активный файл каждого вида.
 * Файлы хранятся на приватном диске (storage/app/private/branding);
 * наружу отдаются только через контроллеры после проверок (§9.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branding_assets', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('mime');
            $table->unsignedBigInteger('bytes');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->char('checksum', 64);
            $table->timestamp('created_at')->nullable();

            $table->unique('kind');
        });

        // CHECK — только PostgreSQL; sqlite ≥ 3.53 понимает синтаксис, старше
        // пропускаем enforcement (см. .ai/rules/backend.md).
        try {
            DB::statement("alter table branding_assets add constraint branding_assets_kind_check check (kind in ('large_logo', 'small_logo'))");
        } catch (QueryException) {
            // SQLite < 3.53: ALTER TABLE ADD CONSTRAINT не поддерживается.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('branding_assets');
    }
};

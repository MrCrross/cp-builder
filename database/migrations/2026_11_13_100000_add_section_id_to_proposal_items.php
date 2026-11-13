<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Позиции стали блоком (секция типа items): строки привязываются к своей
 * секции для публичного рендера. NULL — legacy-строки до миграции, они
 * рендерятся прежней шапочной таблицей «Работы».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposal_items', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable()->after('version_id')->constrained('proposal_sections')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('proposal_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('section_id');
        });
    }
};

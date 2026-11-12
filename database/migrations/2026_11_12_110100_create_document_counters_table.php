<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Атомарная нумерация документов (план §7.2): номер присваивается при первом
 * выпуске формата KP-YYYY-NNNNN; строка года блокируется advisory-локом
 * в транзакции выпуска. Пропуски номеров после rollback допустимы.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_counters', function (Blueprint $table) {
            $table->integer('year')->primary();
            $table->integer('last_number')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_counters');
    }
};

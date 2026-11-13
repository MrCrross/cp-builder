<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog — плоские категории и позиции номенклатуры (план §8.2).
 * Категория с позициями и позиция с историей в КП не удаляются (restrict,
 * план §8.3): вывод из обращения — active=false. Деньги — decimal strings,
 * валюта только RUB (план §7.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();

            $table->index('name');
        });

        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('catalog_categories')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('price', 20, 2);
            $table->string('currency', 3)->default('RUB');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['category_id', 'name']);
            $table->index('active');
        });

        // CHECK — только PostgreSQL; sqlite ≥ 3.53 понимает синтаксис, старше
        // пропускаем enforcement (см. .ai/rules/backend.md).
        try {
            DB::statement("alter table catalog_items add constraint catalog_items_currency_check check (currency = 'RUB')");
            DB::statement('alter table catalog_items add constraint catalog_items_price_check check (price >= 0)');
        } catch (QueryException) {
            // SQLite < 3.53: ALTER TABLE ADD CONSTRAINT не поддерживается.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
        Schema::dropIfExists('catalog_categories');
    }
};

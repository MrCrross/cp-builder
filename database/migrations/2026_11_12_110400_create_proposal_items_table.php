<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ProposalItem — полный snapshot позиции (план §8.2, §7.3): изменение
 * справочника задним числом документ не меняет. catalog_item_id — lineage
 * (внешний ключ к Catalog добавляется на этапе 4, когда появится таблица).
 * quantity/rate/amounts — decimal strings, источник истины — доменные VO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('proposal_versions')->cascadeOnDelete();
            $table->unsignedBigInteger('catalog_item_id')->nullable();
            $table->integer('position');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('quantity_min', 14, 2);
            $table->decimal('quantity_max', 14, 2);
            $table->decimal('unit_price', 20, 2);
            $table->decimal('amount_min', 20, 2);
            $table->decimal('amount_max', 20, 2);
            $table->timestamps();

            $table->index(['version_id', 'position']);
        });

        // CHECK — только PostgreSQL; sqlite ≥ 3.53 понимает синтаксис, старше
        // пропускаем enforcement (см. .ai/rules/backend.md).
        try {
            DB::statement('alter table proposal_items add constraint proposal_items_quantity_check check (quantity_min >= 0 and quantity_max >= quantity_min)');
            DB::statement('alter table proposal_items add constraint proposal_items_price_check check (unit_price >= 0)');
            DB::statement('alter table proposal_items add constraint proposal_items_amount_check check (amount_min >= 0 and amount_max >= amount_min)');
        } catch (QueryException) {
            // SQLite < 3.53: ALTER TABLE ADD CONSTRAINT не поддерживается.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_items');
    }
};

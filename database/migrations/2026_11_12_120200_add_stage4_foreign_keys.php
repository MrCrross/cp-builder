<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Внешние ключи этапа 4 (план §8.3): proposals.project_id и
 * proposal_items.catalog_item_id. Обе связи restrict — проект с КП и
 * позиция с историей в документах не удаляются.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('template_id')->constrained('projects')->restrictOnDelete();
            $table->index('project_id');
        });

        Schema::table('proposal_items', function (Blueprint $table) {
            $table->foreign('catalog_item_id')->references('id')->on('catalog_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('proposal_items', function (Blueprint $table) {
            $table->dropForeign(['catalog_item_id']);
        });

        Schema::table('proposals', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropIndex(['project_id']);
            $table->dropColumn('project_id');
        });
    }
};

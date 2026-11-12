<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proposal — стабильная идентичность документа (план §8.2), без content.
 * project_id добавляется на этапе 4 (Projects). Статусы: draft/issued/archived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->nullable()->constrained('proposal_templates')->nullOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('number')->nullable();
            $table->integer('year')->nullable();
            $table->string('status');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['year', 'number']);
            $table->index(['status', 'updated_at']);
            $table->index('owner_id');
        });

        DB::statement("alter table proposals add constraint proposals_status_check check (status in ('draft', 'issued', 'archived'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('proposals');
    }
};

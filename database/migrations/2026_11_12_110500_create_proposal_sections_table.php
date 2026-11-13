<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ProposalSection — typed discriminated payload (план §8.2):
 * stages/custom/table/license/price_badge. Payload валидируется на save
 * (allowlist типов и полей) и рендерится только серверным strict-рендерером.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('proposal_versions')->cascadeOnDelete();
            $table->integer('position');
            $table->string('type');
            $table->boolean('enabled')->default(true);
            $table->jsonb('payload');
            $table->timestamps();

            $table->index(['version_id', 'position']);
        });

        // CHECK — только PostgreSQL; sqlite ≥ 3.53 понимает синтаксис, старше
        // пропускаем enforcement (см. .ai/rules/backend.md).
        try {
            DB::statement("alter table proposal_sections add constraint proposal_sections_type_check check (type in ('stages', 'custom', 'table', 'license', 'price_badge'))");
        } catch (QueryException) {
            // SQLite < 3.53: ALTER TABLE ADD CONSTRAINT не поддерживается.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_sections');
    }
};

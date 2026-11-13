<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ProposalVersion — полный снимок документа (план §7.3, §8.2): заголовок,
 * клиент, контакты менеджера, note, валюта, totals и branding snapshot.
 *
 * Правила БД:
 * - максимум один draft и один действующий issued на Proposal
 *   (partial unique indexes, §8.3);
 * - content-поля и дети версий issued/superseded неизменяемы — PostgreSQL
 *   trigger (§7.1 п.6); в тестах на sqlite проверка выполняется на
 *   application-уровне;
 * - issued_at определяет дату документа, а не дата рендера.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained('proposals')->cascadeOnDelete();
            $table->integer('version');
            $table->string('state');
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('client_name')->nullable();
            $table->string('client_contact')->nullable();
            $table->string('manager_name')->nullable();
            $table->string('manager_email')->nullable();
            $table->string('manager_phone')->nullable();
            $table->text('note')->nullable();
            $table->char('currency', 3)->default('RUB');
            $table->decimal('total_min', 20, 2)->default('0');
            $table->decimal('total_max', 20, 2)->default('0');
            $table->jsonb('branding_snapshot');
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->unique(['proposal_id', 'version']);
            $table->index(['proposal_id', 'version']);
        });

        DB::statement('create unique index proposal_versions_one_draft on proposal_versions (proposal_id) where state = \'draft\'');
        DB::statement('create unique index proposal_versions_one_issued on proposal_versions (proposal_id) where state = \'issued\'');

        // CHECK — только PostgreSQL; sqlite ≥ 3.53 понимает синтаксис, старше
        // пропускаем enforcement (см. .ai/rules/backend.md).
        try {
            DB::statement('alter table proposal_versions add constraint proposal_versions_state_check check (state in (\'draft\', \'issued\', \'superseded\'))');
            DB::statement('alter table proposal_versions add constraint proposal_versions_version_check check (version >= 1)');
            DB::statement('alter table proposal_versions add constraint proposal_versions_currency_check check (currency = \'RUB\')');
            DB::statement('alter table proposal_versions add constraint proposal_versions_totals_check check (total_min >= 0 and total_max >= total_min)');
        } catch (QueryException) {
            // SQLite < 3.53: ALTER TABLE ADD CONSTRAINT не поддерживается.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_versions');
    }
};

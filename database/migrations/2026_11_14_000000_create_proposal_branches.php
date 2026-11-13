<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ветки версий КП (git-модель): версии живут в ветках, у ветки есть
 * указатель current_version_id (как git ref). Основная (main) ветка на
 * предложение — одна; публичная main-ссылка (public_links.branch_id is null)
 * резолвится через неё и показывает её текущую версию. Остальные ветки
 * имеют собственные ссылки (public_links.branch_id).
 *
 * Правила БД:
 * - один main на Proposal (partial unique);
 * - максимум один draft и один действующий issued на ветку (partial unique,
 *   ранее — на предложение);
 * - один активный main-link на Proposal и один активный link на ветку.
 *
 * Бэкфилл: для каждого существующего предложения создаётся ветка main,
 * все версии привязываются к ней; указатель — issued-версия, иначе
 * последняя по номеру. Существующая ссылка остаётся main-ссылкой.
 *
 * ВАЖНО про порядок: на SQLite change()/dropColumn пересоздают таблицу
 * и все её индексы через grammar, которая теряет WHERE partial unique
 * index. Поэтому все raw partial-индексы создаются ПОСЛЕДНИМИ — после
 * любых Schema::table с change/FK по этим таблицам.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained('proposals')->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('is_main')->default(false);
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamps();

            $table->index('current_version_id');
            $table->unique(['proposal_id', 'name']);
        });

        Schema::table('proposal_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->index('branch_id');
        });

        // Бэкфилл: main-ветка на каждое предложение + привязка версий.
        // Триггер иммутабельности (pgsql) блокирует UPDATE выпущенных
        // версий — на время бэкфилла он отключается, техническое поле
        // branch_id контент версии не меняет.
        $isPgsql = DB::getDriverName() === 'pgsql';

        if ($isPgsql) {
            DB::statement('alter table proposal_versions disable trigger proposals_versions_immutable');
        }

        try {
            $proposalIds = DB::table('proposals')->pluck('id');

            foreach ($proposalIds as $proposalId) {
                $versions = DB::table('proposal_versions')
                    ->where('proposal_id', $proposalId)
                    ->orderBy('version')
                    ->get(['id', 'version', 'state']);

                if ($versions->isEmpty()) {
                    continue;
                }

                $current = $versions->firstWhere('state', 'issued') ?? $versions->last();

                $branchId = DB::table('proposal_branches')->insertGetId([
                    'proposal_id' => $proposalId,
                    'name' => 'main',
                    'is_main' => true,
                    'current_version_id' => $current->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('proposal_versions')
                    ->where('proposal_id', $proposalId)
                    ->update(['branch_id' => $branchId]);
            }
        } finally {
            if ($isPgsql) {
                DB::statement('alter table proposal_versions enable trigger proposals_versions_immutable');
            }
        }

        // FK current_version_id — добавляет rebuild таблицы (SQLite).
        Schema::table('proposal_branches', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('proposal_versions')->nullOnDelete();
        });

        Schema::table('proposal_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable(false)->change();
            $table->foreign('branch_id')->references('id')->on('proposal_branches')->cascadeOnDelete();
        });

        // public_links: колонка + FK (rebuild таблицы) — ДО partial-индексов.
        Schema::table('public_links', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->index('branch_id');
        });

        Schema::table('public_links', function (Blueprint $table) {
            $table->foreign('branch_id')->references('id')->on('proposal_branches')->cascadeOnDelete();
        });

        // -- Далее только raw statement: partial unique-индексы (после всех
        // rebuild'ов, иначе SQLite-grammar потеряет WHERE при пересоздании).

        DB::statement('drop index if exists proposal_versions_one_draft');
        DB::statement('drop index if exists proposal_versions_one_issued');
        DB::statement('create unique index proposal_versions_one_draft on proposal_versions (branch_id) where state = \'draft\'');
        DB::statement('create unique index proposal_versions_one_issued on proposal_versions (branch_id) where state = \'issued\'');

        DB::statement('create unique index proposal_branches_one_main on proposal_branches (proposal_id) where is_main');

        DB::statement('drop index if exists public_links_one_active');
        DB::statement('create unique index public_links_one_active_main on public_links (proposal_id) where revoked_at is null and branch_id is null');
        DB::statement('create unique index public_links_one_active_branch on public_links (branch_id) where revoked_at is null');
    }

    public function down(): void
    {
        Schema::table('public_links', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
        });

        Schema::table('proposal_branches', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });

        Schema::table('public_links', function (Blueprint $table) {
            $table->dropIndex(['branch_id']);
            $table->dropColumn('branch_id');
        });

        Schema::table('proposal_versions', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropIndex(['branch_id']);
            $table->dropColumn('branch_id');
        });

        Schema::dropIfExists('proposal_branches');

        // Rebuild'и выше пересоздали индексы без WHERE — восстанавливаем
        // прежние partial-индексы заново (raw, последним шагом).
        DB::statement('drop index if exists public_links_one_active_branch');
        DB::statement('drop index if exists public_links_one_active_main');
        DB::statement('create unique index public_links_one_active on public_links (proposal_id) where revoked_at is null');

        DB::statement('drop index if exists proposal_branches_one_main');
        DB::statement('drop index if exists proposal_versions_one_issued');
        DB::statement('drop index if exists proposal_versions_one_draft');
        DB::statement('create unique index proposal_versions_one_draft on proposal_versions (proposal_id) where state = \'draft\'');
        DB::statement('create unique index proposal_versions_one_issued on proposal_versions (proposal_id) where state = \'issued\'');
    }
};

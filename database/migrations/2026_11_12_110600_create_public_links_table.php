<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Public links (план §8.2, §9.3): случайный ≥256-bit token, в БД только
 * SHA-256 hash; полный token показывается один раз, при утрате — rotation.
 * Один активный link на Proposal (partial unique). Archived Proposal
 * не рендерится даже при валидном token (проверка на application-уровне).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained('proposals')->cascadeOnDelete();
            $table->char('token_hash', 64);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->unique('token_hash');
        });

        DB::statement('create unique index public_links_one_active on public_links (proposal_id) where revoked_at is null');

        Schema::create('public_link_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained('proposals')->cascadeOnDelete();
            $table->string('legacy_slug');
            $table->timestamp('created_at')->nullable();

            $table->unique('legacy_slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_link_aliases');
        Schema::dropIfExists('public_links');
    }
};

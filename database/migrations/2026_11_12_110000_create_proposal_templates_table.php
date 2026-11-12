<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Шаблоны КП (план §8.2): typed payload default_content JSONB; создание КП
 * копирует контент, поэтому link on delete set null (§8.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('default_title');
            $table->string('default_subtitle')->nullable();
            $table->jsonb('default_content');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_templates');
    }
};

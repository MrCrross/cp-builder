<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Imports — staging-контур XML/YML-импорта номенклатуры (план §13.1).
 * Файл → import_jobs/import_files (quarantine-ссылки и hash) → потоковый
 * parse → import_rows (normalized payload, action add/change/skip/delete,
 * row errors). Каталог не трогаем до явного подтверждения apply.
 * Дерево категорий источника не сохраняем (решение владельца): в КП нужны
 * картинка, название, характеристики и ссылка на источник.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('profile');
            $table->string('status')->default('uploaded');
            $table->text('error_message')->nullable();
            $table->json('property_dictionary')->nullable();
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_add')->default(0);
            $table->unsignedInteger('rows_change')->default(0);
            $table->unsignedInteger('rows_skip')->default(0);
            $table->unsignedInteger('rows_delete')->default(0);
            $table->unsignedInteger('rows_error')->default(0);
            $table->unsignedInteger('rows_processed')->default(0);
            $table->unsignedInteger('applied_created')->default(0);
            $table->unsignedInteger('applied_updated')->default(0);
            $table->unsignedInteger('applied_deactivated')->default(0);
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['profile', 'status']);
        });

        Schema::create('import_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('import_jobs')->cascadeOnDelete();
            $table->string('original_name');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->string('quarantine_path');
            $table->string('detected_kind')->nullable();
            $table->unsignedInteger('parsed_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['job_id', 'sha256']);
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('import_jobs')->cascadeOnDelete();
            $table->string('external_id');
            $table->string('action');
            $table->json('payload');
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->unique(['job_id', 'external_id']);
            $table->index(['job_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_files');
        Schema::dropIfExists('import_jobs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Связь каталога с импортами: внешние ключи источников (план §13.1 —
 * unique (source, external_id), дедупликация внутри источника; источники
 * «commerceml» и «yml» независимы). NULL — ручные позиции каталога.
 *
 * Поля импорта: properties — характеристики позиции json-списком
 * [{name, value}] (решение владельца этапа 5); image — ссылка на картинку
 * (внешний URL для YML, путь в storage для скачанных из 1С); source_url —
 * ссылка на страницу товара в источнике (YML <url>).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->string('source')->nullable();
            $table->string('external_id')->nullable();
            $table->json('properties')->nullable();
            $table->string('image')->nullable();
            $table->string('source_url')->nullable();

            $table->unique(['source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropUnique(['source', 'external_id']);
            $table->dropColumn(['source', 'external_id', 'properties', 'image', 'source_url']);
        });
    }
};

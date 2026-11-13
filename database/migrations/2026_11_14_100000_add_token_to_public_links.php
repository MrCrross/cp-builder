<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отказ от одноразовых ссылок: полный token теперь хранится в БД
 * (колонка token) рядом с SHA-256 hash (token_hash, lookup-индекс
 * неизменен). Ссылка публична по определению, а данные КП лежат в той же
 * БД, поэтому хранение token не расширяет доступы при утечке БД.
 * Существующие строки: token = NULL (восстановить нельзя) — ссылка
 * работает, URL доступен после rotation.
 * Только добавление колонки: на SQLite rebuild таблицы не происходит,
 * partial unique-индексы выживают.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_links', function (Blueprint $table) {
            $table->string('token')->nullable()->after('token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('public_links', function (Blueprint $table) {
            $table->dropColumn('token');
        });
    }
};

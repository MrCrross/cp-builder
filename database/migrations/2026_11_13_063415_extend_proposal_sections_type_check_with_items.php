<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Блок «Позиции» стал полноценной секцией: type 'items' разрешён CHECK-ом.
 * Синтаксис drop/add constraint совпадает у pgsql и sqlite (3.53+).
 */
return new class extends Migration
{
    private const OLD_TYPES = "('stages', 'custom', 'table', 'license', 'price_badge')";

    private const NEW_TYPES = "('items', 'stages', 'custom', 'table', 'license', 'price_badge')";

    public function up(): void
    {
        DB::statement('alter table proposal_sections drop constraint proposal_sections_type_check');
        DB::statement('alter table proposal_sections add constraint proposal_sections_type_check check (type in '.self::NEW_TYPES.')');
    }

    public function down(): void
    {
        DB::statement('alter table proposal_sections drop constraint proposal_sections_type_check');
        DB::statement('alter table proposal_sections add constraint proposal_sections_type_check check (type in '.self::OLD_TYPES.')');
    }
};

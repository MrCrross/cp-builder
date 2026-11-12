<?php

namespace App\Modules\CommercialProposals\Infrastructure\Persistence;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Атомарная нумерация KP-YYYY-NNNNN (план §7.2). PostgreSQL: advisory
 * xact-лок по году сериализует конкурентные выпуски; sqlite (тесты) —
 * без лока, гонки невозможны в in-memory тестах. Пропуски номеров после
 * rollback допустимы (план §7.2).
 */
final class DocumentNumberGenerator
{
    public function next(int $year): int
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["kp-counter-{$year}"]);
        }

        $row = DB::table('document_counters')->where('year', $year)->first();

        if ($row === null) {
            try {
                DB::table('document_counters')->insert(['year' => $year, 'last_number' => 1]);
            } catch (QueryException) {
                // Конкурентная вставка того же года: перечитываем.
                $row = DB::table('document_counters')->where('year', $year)->firstOrFail();
            }
        }

        if ($row !== null) {
            DB::table('document_counters')->where('year', $year)->update([
                'last_number' => $row->last_number + 1,
            ]);
        }

        return (int) DB::table('document_counters')->where('year', $year)->value('last_number');
    }
}

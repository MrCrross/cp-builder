<?php

namespace App\Modules\CommercialProposals\Support;

use App\Modules\CommercialProposals\Models\DocumentCounter;
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

        $counter = DocumentCounter::query()->where('year', $year)->first();

        if ($counter === null) {
            try {
                DocumentCounter::query()->create(['year' => $year, 'last_number' => 1]);

                return 1;
            } catch (QueryException) {
                // Конкурентная вставка того же года: перечитываем.
                $counter = DocumentCounter::query()->where('year', $year)->firstOrFail();
            }
        }

        $counter->last_number = $counter->last_number + 1;
        $counter->save();

        return $counter->last_number;
    }
}

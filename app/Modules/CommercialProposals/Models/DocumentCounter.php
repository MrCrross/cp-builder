<?php

namespace App\Modules\CommercialProposals\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Счётчик номеров документов по годам (план §7.2) — единственная таблица
 * модуля без timestamps; PK — год.
 *
 * @property int $year
 * @property int $last_number
 */
class DocumentCounter extends Model
{
    protected $table = 'document_counters';

    protected $primaryKey = 'year';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}

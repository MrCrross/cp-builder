<?php

namespace App\Modules\Statistics\Models;

use App\Modules\CommercialProposals\Models\Proposal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Дневная разбивка visits по измерению (device_type/os) одного КП.
 * Системная разбивка дашборд считает SUM-группировкой по измерению.
 */
class StatisticsDailyDimension extends Model
{
    protected $table = 'statistics_daily_dimensions';

    protected $fillable = [
        'proposal_id',
        'date',
        'dimension',
        'value',
        'visits',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'visits' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Proposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }
}

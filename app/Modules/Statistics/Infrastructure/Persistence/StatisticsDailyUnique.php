<?php

namespace App\Modules\Statistics\Infrastructure\Persistence;

use App\Modules\CommercialProposals\Infrastructure\Persistence\Proposal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Дедуп-сет дневных уников (ADR-0011): существование строки означает,
 * что данный visitor_hash уже учтён в unique_visitors дневного агрегата.
 */
class StatisticsDailyUnique extends Model
{
    public $timestamps = false;

    protected $table = 'statistics_daily_uniques';

    protected $fillable = [
        'proposal_id',
        'date',
        'visitor_hash',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
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

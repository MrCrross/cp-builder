<?php

namespace App\Modules\Statistics\Models;

use App\Modules\CommercialProposals\Models\Proposal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Дневной агрегат просмотров одного КП (план §13.5).
 */
class StatisticsDailyProposal extends Model
{
    protected $table = 'statistics_daily_proposals';

    protected $fillable = [
        'proposal_id',
        'date',
        'visits',
        'unique_visitors',
        'bot_requests',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'visits' => 'integer',
            'unique_visitors' => 'integer',
            'bot_requests' => 'integer',
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

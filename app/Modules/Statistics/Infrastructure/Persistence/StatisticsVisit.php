<?php

namespace App\Modules\Statistics\Infrastructure\Persistence;

use App\Modules\CommercialProposals\Infrastructure\Persistence\Proposal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Raw buffer просмотров (ADR-0011): короткий TTL, без PII. Классификация
 * уже выполнена при записи — строка нужна только для retention-проверок
 * и сверки приёмки.
 */
class StatisticsVisit extends Model
{
    public $timestamps = false;

    protected $table = 'statistics_visits';

    protected $fillable = [
        'proposal_id',
        'visited_at',
        'is_bot',
        'device_type',
        'os',
        'visitor_hash',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
            'is_bot' => 'boolean',
            'created_at' => 'datetime',
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

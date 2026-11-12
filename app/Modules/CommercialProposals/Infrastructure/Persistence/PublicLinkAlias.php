<?php

namespace App\Modules\CommercialProposals\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Сохранение старого /kp/{slug} после миграции (план §8.2): старый URL
 * продолжает работать, slug не попадает в логи.
 *
 * @property int $id
 * @property int $proposal_id
 * @property string $legacy_slug
 */
class PublicLinkAlias extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'public_link_aliases';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
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

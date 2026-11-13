<?php

namespace App\Modules\CommercialProposals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Публичная ссылка (план §9.3): в БД только SHA-256 hash токена, полный
 * ≥256-bit token показывается один раз при создании/rotation.
 *
 * @property int $id
 * @property int $proposal_id
 * @property string $token_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $revoked_at
 */
class PublicLink extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'public_links';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Proposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}

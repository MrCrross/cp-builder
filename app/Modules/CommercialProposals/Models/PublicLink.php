<?php

namespace App\Modules\CommercialProposals\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Публичная ссылка (план §9.3): полный ≥256-bit token хранится в БД
 * (колонка token) и показывается в интерфейсе в любой момент; SHA-256
 * hash (token_hash) — для lookup по маршруту /kp/{token}. У строк,
 * созданных до перехода, token = NULL: ссылка работает, URL доступен
 * после rotation.
 *
 * @property int $id
 * @property int $proposal_id
 * @property int|null $branch_id null — main-ссылка (резолвится через main-ветку предложения); иначе — ссылка ветки
 * @property string $token_hash
 * @property string|null $token
 * @property Carbon|null $created_at
 * @property Carbon|null $revoked_at
 * @property-read ProposalBranch|null $branch
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

    /**
     * @return BelongsTo<ProposalBranch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(ProposalBranch::class, 'branch_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}

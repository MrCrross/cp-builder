<?php

namespace App\Modules\CommercialProposals\Models;

use Database\Factories\ProposalBranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Ветка версий КП (git-модель): указатель current_version_id — как git ref,
 * двигается при выпуске версии в ветку. Одна ветка на предложение — main
 * (partial unique index); публичная main-ссылка резолвится через неё,
 * остальные ветки имеют собственные ссылки (public_links.branch_id).
 *
 * @property int $id
 * @property int $proposal_id
 * @property string $name
 * @property bool $is_main
 * @property int|null $current_version_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Proposal $proposal
 * @property-read ProposalVersion|null $currentVersion
 * @property-read PublicLink|null $publicLink активная ссылка ветки (не main)
 */
class ProposalBranch extends Model
{
    /** @use HasFactory<ProposalBranchFactory> */
    use HasFactory;

    protected static function newFactory(): ProposalBranchFactory
    {
        return ProposalBranchFactory::new();
    }

    protected $table = 'proposal_branches';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
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
     * @return BelongsTo<ProposalVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ProposalVersion::class, 'current_version_id');
    }

    /**
     * @return HasMany<ProposalVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ProposalVersion::class, 'branch_id')->orderByDesc('version');
    }

    /**
     * @return HasOne<PublicLink, $this>
     */
    public function publicLink(): HasOne
    {
        return $this->hasOne(PublicLink::class, 'branch_id')->whereNull('revoked_at');
    }
}

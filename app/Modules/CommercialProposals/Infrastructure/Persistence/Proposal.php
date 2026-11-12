<?php

namespace App\Modules\CommercialProposals\Infrastructure\Persistence;

use App\Modules\CommercialProposals\Domain\ProposalStatus;
use App\Modules\Projects\Infrastructure\Persistence\Project;
use Database\Factories\ProposalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $template_id
 * @property int|null $project_id
 * @property int $owner_id
 * @property string|null $number
 * @property int|null $year
 * @property ProposalStatus $status
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read ProposalVersion|null $draftVersion
 * @property-read ProposalVersion|null $issuedVersion
 */
class Proposal extends Model
{
    /** @use HasFactory<ProposalFactory> */
    use HasFactory;

    protected static function newFactory(): ProposalFactory
    {
        return ProposalFactory::new();
    }

    protected $table = 'proposals';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ProposalStatus::class,
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ProposalTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ProposalTemplate::class, 'template_id');
    }

    /**
     * Проект группировки (модуль Projects, план §8.2); FK restrict —
     * проект с КП не удаляется.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * @return HasMany<ProposalVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ProposalVersion::class)->orderByDesc('version');
    }

    /**
     * @return HasOne<ProposalVersion, $this>
     */
    public function draftVersion(): HasOne
    {
        return $this->hasOne(ProposalVersion::class)->where('state', 'draft');
    }

    /**
     * @return HasOne<ProposalVersion, $this>
     */
    public function issuedVersion(): HasOne
    {
        return $this->hasOne(ProposalVersion::class)->where('state', 'issued');
    }

    /**
     * @return HasMany<PublicLink, $this>
     */
    public function publicLinks(): HasMany
    {
        return $this->hasMany(PublicLink::class);
    }

    /**
     * @return HasMany<PublicLinkAlias, $this>
     */
    public function publicLinkAliases(): HasMany
    {
        return $this->hasMany(PublicLinkAlias::class);
    }

    public function isDraft(): bool
    {
        return $this->status === ProposalStatus::Draft;
    }
}

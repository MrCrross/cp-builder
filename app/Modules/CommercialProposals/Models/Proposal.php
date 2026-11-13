<?php

namespace App\Modules\CommercialProposals\Models;

use App\Modules\CommercialProposals\Domain\Enums\ProposalStatus;
use App\Modules\Projects\Models\Project;
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
 * @property-read ProposalBranch|null $mainBranch
 * @property-read ProposalVersion|null $mainVersion текущая версия main-ветки (то, что видно по основной ссылке)
 * @property-read bool $has_draft псевдо-атрибут withExists (индекс реестра)
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
     * Ветки версий (git-модель): версии живут в ветках, публичные ссылки
     * резолвятся через current_version_id ветки.
     *
     * @return HasMany<ProposalBranch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(ProposalBranch::class)->orderByDesc('is_main')->orderBy('name');
    }

    /**
     * @return HasOne<ProposalBranch, $this>
     */
    public function mainBranch(): HasOne
    {
        return $this->hasOne(ProposalBranch::class)->where('is_main', true);
    }

    /**
     * Текущая версия main-ветки — документ по основной (main) ссылке.
     */
    public function mainVersion(): ?ProposalVersion
    {
        return $this->mainBranch?->currentVersion;
    }

    /**
     * Черновик ветки: по умолчанию main.
     */
    public function draftOnBranch(?int $branchId = null): ?ProposalVersion
    {
        $branchId ??= $this->mainBranch?->id;

        if ($branchId === null) {
            return null;
        }

        return $this->versions()->where('branch_id', $branchId)->where('state', 'draft')->first();
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

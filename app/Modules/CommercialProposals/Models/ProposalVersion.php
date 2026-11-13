<?php

namespace App\Modules\CommercialProposals\Models;

use App\Modules\CommercialProposals\Domain\Enums\VersionState;
use Database\Factories\ProposalVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Полный снимок версии документа (план §7.3). Выпущенные версии
 * неизменяемы: application-уровень и PostgreSQL trigger.
 *
 * @property int $id
 * @property int $proposal_id
 * @property int $version
 * @property VersionState $state
 * @property string $title
 * @property string|null $subtitle
 * @property string|null $client_name
 * @property string|null $client_contact
 * @property string|null $manager_name
 * @property string|null $manager_email
 * @property string|null $manager_phone
 * @property string|null $note
 * @property string $currency
 * @property string $total_min
 * @property string $total_max
 * @property array<string, mixed> $branding_snapshot
 * @property int $lock_version
 * @property int $created_by
 * @property Carbon|null $issued_at
 */
class ProposalVersion extends Model
{
    /** @use HasFactory<ProposalVersionFactory> */
    use HasFactory;

    protected static function newFactory(): ProposalVersionFactory
    {
        return ProposalVersionFactory::new();
    }

    protected $table = 'proposal_versions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'state' => VersionState::class,
            'branding_snapshot' => 'array',
            'issued_at' => 'datetime',
            'lock_version' => 'integer',
            'total_min' => 'decimal:2',
            'total_max' => 'decimal:2',
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
     * @return HasMany<ProposalItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ProposalItem::class, 'version_id')->orderBy('position');
    }

    /**
     * @return HasMany<ProposalSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(ProposalSection::class, 'version_id')->orderBy('position');
    }

    public function isDraft(): bool
    {
        return $this->state === VersionState::Draft;
    }
}

<?php

namespace App\Modules\CommercialProposals\Models;

use App\Modules\CommercialProposals\Domain\Enums\SectionType;
use Database\Factories\ProposalSectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Typed section (план §8.2): payload проходит strict-валидацию allowlist'ом
 * типов и полей на save; рендерится только серверным strict-рендерером.
 *
 * @property int $id
 * @property int $version_id
 * @property int $position
 * @property SectionType $type
 * @property bool $enabled
 * @property array<string, mixed> $payload
 */
class ProposalSection extends Model
{
    /** @use HasFactory<ProposalSectionFactory> */
    use HasFactory;

    protected static function newFactory(): ProposalSectionFactory
    {
        return ProposalSectionFactory::new();
    }

    protected $table = 'proposal_sections';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => SectionType::class,
            'enabled' => 'boolean',
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ProposalVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ProposalVersion::class, 'version_id');
    }
}

<?php

namespace App\Modules\CommercialProposals\Infrastructure\Persistence;

use Database\Factories\ProposalTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $default_title
 * @property string|null $default_subtitle
 * @property array<string, mixed> $default_content
 * @property bool $active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ProposalTemplate extends Model
{
    /** @use HasFactory<ProposalTemplateFactory> */
    use HasFactory;

    protected static function newFactory(): ProposalTemplateFactory
    {
        return ProposalTemplateFactory::new();
    }

    protected $table = 'proposal_templates';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'default_content' => 'array',
            'active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Proposal, $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class, 'template_id');
    }
}

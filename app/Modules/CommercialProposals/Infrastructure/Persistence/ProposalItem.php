<?php

namespace App\Modules\CommercialProposals\Infrastructure\Persistence;

use Database\Factories\ProposalItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot позиции КП (план §7.3, §8.2). Деньги/количество — decimal
 * strings (transport numeric); доменные VO — единственный источник расчётов.
 *
 * @property int $id
 * @property int $version_id
 * @property int|null $catalog_item_id
 * @property int $position
 * @property string $name
 * @property string|null $description
 * @property string|null $unit
 * @property string $quantity_min
 * @property string $quantity_max
 * @property string $unit_price
 * @property string $amount_min
 * @property string $amount_max
 */
class ProposalItem extends Model
{
    /** @use HasFactory<ProposalItemFactory> */
    use HasFactory;

    protected static function newFactory(): ProposalItemFactory
    {
        return ProposalItemFactory::new();
    }

    protected $table = 'proposal_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity_min' => 'decimal:2',
            'quantity_max' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'amount_min' => 'decimal:2',
            'amount_max' => 'decimal:2',
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

<?php

namespace App\Modules\Projects\Models;

use App\Models\User;
use App\Modules\CommercialProposals\Models\Proposal;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $client_name
 * @property string|null $client_contact
 * @property int $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $creator
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected static function newFactory(): ProjectFactory
    {
        return ProjectFactory::new();
    }

    protected $table = 'projects';

    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * КП проекта; модуль Proposals связывается через project_id
     * (план §8.3), удаление проекта с КП запрещено на уровне FK.
     *
     * @return HasMany<Proposal, $this>
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }
}

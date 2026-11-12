<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Audit-запись неизменяема: создаётся Audit-сервисом и дальше только читается
 * (план §9.6). Ни пароли, ни сессии, ни токены сюда не попадают —
 * фильтрация обязательна на уровне сервиса.
 *
 * @property int $id
 * @property int|null $actor_id
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $request_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property Carbon $created_at
 */
class AuditEntry extends Model
{
    /**
     * Записи не обновляются.
     */
    public const UPDATED_AT = null;

    protected $table = 'audit_log';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}

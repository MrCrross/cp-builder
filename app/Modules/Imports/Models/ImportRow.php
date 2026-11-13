<?php

namespace App\Modules\Imports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Staging-строка: normalized payload (ParsedItem), action относительно
 * каталога на момент parse, row errors. План §13.1 — raw XML здесь не
 * храним, только нормализованные поля.
 *
 * @property int $id
 * @property int $job_id
 * @property string $external_id
 * @property string $action add|change|skip|delete
 * @property array<string, mixed> $payload
 * @property array<int, string>|null $errors
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read ImportJob $job
 */
class ImportRow extends Model
{
    protected $table = 'import_rows';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'errors' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ImportJob, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class, 'job_id');
    }
}

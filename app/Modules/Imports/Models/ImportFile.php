<?php

namespace App\Modules\Imports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Один загруженный файл job'а: quarantine-путь, hash, вид, итог разбора.
 *
 * @property int $id
 * @property int $job_id
 * @property string $original_name
 * @property string $sha256
 * @property int $size_bytes
 * @property string $quarantine_path
 * @property string|null $detected_kind
 * @property int $parsed_count
 * @property string|null $error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read ImportJob $job
 */
class ImportFile extends Model
{
    protected $table = 'import_files';

    protected $guarded = [];

    /**
     * @return BelongsTo<ImportJob, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class, 'job_id');
    }
}

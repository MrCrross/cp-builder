<?php

namespace App\Modules\Imports\Infrastructure\Persistence;

use App\Models\User;
use Database\Factories\ImportJobFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Job импорта: uploaded → validating → awaiting_confirmation → processing →
 * terminal (план §13.1). Файлы лежат в private-quarantine (disk local),
 * staging — в import_rows; сам каталог до подтверждения не трогаем.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $profile commerceml|yml
 * @property string $status
 * @property string|null $error_message
 * @property int $rows_total
 * @property int $rows_add
 * @property int $rows_change
 * @property int $rows_skip
 * @property int $rows_delete
 * @property int $rows_error
 * @property int $applied_created
 * @property int $applied_updated
 * @property int $applied_deactivated
 * @property Carbon|null $applied_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, ImportFile> $files
 * @property-read Collection<int, ImportRow> $rows
 */
class ImportJob extends Model
{
    /** @use HasFactory<ImportJobFactory> */
    use HasFactory;

    protected static function newFactory(): ImportJobFactory
    {
        return ImportJobFactory::new();
    }

    protected $table = 'import_jobs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'applied_at' => 'datetime',
            'property_dictionary' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<ImportFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(ImportFile::class, 'job_id');
    }

    /**
     * @return HasMany<ImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class, 'job_id');
    }
}

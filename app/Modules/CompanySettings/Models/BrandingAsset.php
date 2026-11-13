<?php

namespace App\Modules\CompanySettings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Метаданные активного логотипа (план §8.2). Файл на приватном диске,
 * запись — неизменяемая metadata; замена = новая запись + удаление старой
 * файла после commit. Выпущенные документы хранят собственные копии файлов
 * (snapshot, §7.3), поэтому замена бренда их не меняет.
 *
 * @property int $id
 * @property string $kind large_logo|small_logo
 * @property string $disk
 * @property string $path
 * @property string $mime
 * @property int $bytes
 * @property int|null $width
 * @property int|null $height
 * @property string $checksum
 * @property Carbon|null $created_at
 */
class BrandingAsset extends Model
{
    public const KIND_LARGE = 'large_logo';

    public const KIND_SMALL = 'small_logo';

    public const UPDATED_AT = null;

    protected $table = 'branding_assets';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}

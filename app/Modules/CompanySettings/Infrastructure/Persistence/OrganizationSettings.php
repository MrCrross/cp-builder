<?php

namespace App\Modules\CompanySettings\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/**
 * Singleton-настройки организации (план §10.1). Строка всегда одна (id=1,
 * check-constraint в миграции); доступ через current() с кэшем,
 * инвалидация — при любом обновлении.
 *
 * @property int $id
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $city
 * @property string|null $website
 * @property string|null $slogan
 */
class OrganizationSettings extends Model
{
    public const CACHE_KEY = 'organization_settings.current';

    protected $table = 'organization_settings';

    protected $guarded = [];

    /**
     * Текущие настройки: из кэша, при первом обращении — из БД или
     * создаётся запись дефолтами (id всегда 1). В кэше — массив атрибутов,
     * а не сериализованная модель: переживает перенос класса и не даёт
     * __PHP_Incomplete_Class на stale-записях.
     */
    public static function current(): self
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            $model = new self;
            $model->exists = true;
            $model->forceFill($cached);

            return $model;
        }

        $settings = self::query()->first();

        if ($settings === null) {
            try {
                $settings = self::createDefault();
            } catch (QueryException) {
                // Конкурентное первое создание: читаем созданную строку.
                $settings = self::query()->firstOrFail();
            }
        }

        Cache::forever(self::CACHE_KEY, $settings->attributesToArray());

        return $settings;
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected static function createDefault(): self
    {
        return self::query()->create([
            'id' => 1,
            'name' => config('app.name'),
        ]);
    }
}

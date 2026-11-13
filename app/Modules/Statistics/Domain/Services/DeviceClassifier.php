<?php

namespace App\Modules\Statistics\Domain\Services;

/**
 * Coarse-классификация типа устройства по User-Agent без внешних
 * библиотек (ADR-0011). Гранулярность — dashboard-уровень
 * (desktop/mobile/tablet/unknown).
 */
final class DeviceClassifier
{
    public const DESKTOP = 'desktop';

    public const MOBILE = 'mobile';

    public const TABLET = 'tablet';

    public const UNKNOWN = 'unknown';

    public static function detect(?string $userAgent): string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return self::UNKNOWN;
        }

        $ua = mb_strtolower($userAgent);

        if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet')) {
            return self::TABLET;
        }

        if (preg_match('/(mobile|iphone|ipod|android(?!.*tablet)|windows phone|blackberry|operamini|opera mini)/', $ua) === 1) {
            return self::MOBILE;
        }

        if (str_contains($ua, 'windows') || str_contains($ua, 'macintosh') || str_contains($ua, 'mac os') || str_contains($ua, 'linux') || str_contains($ua, 'x11')) {
            return self::DESKTOP;
        }

        return self::UNKNOWN;
    }
}

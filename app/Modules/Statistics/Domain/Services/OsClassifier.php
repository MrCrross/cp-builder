<?php

namespace App\Modules\Statistics\Domain\Services;

/**
 * Coarse-классификация ОС по User-Agent без внешних библиотек
 * (ADR-0011). Гранулярность — dashboard-уровень.
 */
final class OsClassifier
{
    public const WINDOWS = 'Windows';

    public const MACOS = 'macOS';

    public const LINUX = 'Linux';

    public const ANDROID = 'Android';

    public const IOS = 'iOS';

    public const UNKNOWN = 'unknown';

    public static function detect(?string $userAgent): string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return self::UNKNOWN;
        }

        $ua = mb_strtolower($userAgent);

        if (str_contains($ua, 'android')) {
            return self::ANDROID;
        }

        if (str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ipod')) {
            return self::IOS;
        }

        if (str_contains($ua, 'windows')) {
            return self::WINDOWS;
        }

        if (str_contains($ua, 'macintosh') || str_contains($ua, 'mac os')) {
            return self::MACOS;
        }

        if (str_contains($ua, 'linux') || str_contains($ua, 'x11')) {
            return self::LINUX;
        }

        return self::UNKNOWN;
    }
}

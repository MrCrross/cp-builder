<?php

namespace App\Modules\Statistics\Support;

/**
 * Coarse-профиль посетителя для агрегатов статистики (ADR-0011, addendum):
 * значения существующей кардинальности (device_type: desktop/mobile/tablet/
 * unknown; os: Windows/macOS/Linux/Android/iOS/unknown), нормализованные
 * из matomo/device-detector в UaProfileResolver.
 */
final readonly class UaProfile
{
    public const DEVICE_DESKTOP = 'desktop';

    public const DEVICE_MOBILE = 'mobile';

    public const DEVICE_TABLET = 'tablet';

    public const DEVICE_UNKNOWN = 'unknown';

    public const OS_WINDOWS = 'Windows';

    public const OS_MACOS = 'macOS';

    public const OS_LINUX = 'Linux';

    public const OS_ANDROID = 'Android';

    public const OS_IOS = 'iOS';

    public const OS_UNKNOWN = 'unknown';

    public function __construct(
        public bool $isBot,
        public string $deviceType,
        public string $os,
    ) {}
}

<?php

namespace App\Modules\Statistics\Support;

use DeviceDetector\DeviceDetector;
use DeviceDetector\Parser\Device\AbstractDeviceParser;
use DeviceDetector\Parser\OperatingSystem;

/**
 * Адаптер нормализации matomo/device-detector в coarse-профиль дашборда
 * (ADR-0011, addendum): один парсинг на вызов, на выходе — только
 * сигналы существующей кардинальности (isBot/device_type/os).
 *
 * Два сигнала наследия собственных классификаторов сохранены явно,
 * потому что device-detector их не даёт:
 *  - пустой/отсутствующий UA считается ботом (скрипты без UA не попадают в uniques);
 *  - сигнатуры HTTP-клиентов и инструментов (curl, wget, python-requests,
 *    headlesschrome и т.п.) идут ботами поверх библиотечной базы ботов.
 */
final class UaProfileResolver
{
    private const TOOL_SIGNATURES = [
        'curl', 'wget', 'python-requests', ' okhttp', 'httpclient', 'postman',
        'insomnia', 'scrapy', 'headlesschrome', 'phantomjs',
    ];

    public function resolve(?string $userAgent): UaProfile
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return new UaProfile(
                isBot: true,
                deviceType: UaProfile::DEVICE_UNKNOWN,
                os: UaProfile::OS_UNKNOWN,
            );
        }

        $detector = new DeviceDetector($userAgent);
        $detector->parse();

        return new UaProfile(
            isBot: $detector->isBot() || $this->isTool($userAgent),
            deviceType: $this->deviceType($detector),
            os: $this->os($detector),
        );
    }

    private function isTool(string $userAgent): bool
    {
        $normalized = ' '.mb_strtolower($userAgent).' ';

        foreach (self::TOOL_SIGNATURES as $signature) {
            if (str_contains($normalized, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function deviceType(DeviceDetector $detector): string
    {
        return match ($detector->getDevice()) {
            AbstractDeviceParser::DEVICE_TYPE_DESKTOP => UaProfile::DEVICE_DESKTOP,
            AbstractDeviceParser::DEVICE_TYPE_SMARTPHONE,
            AbstractDeviceParser::DEVICE_TYPE_FEATURE_PHONE,
            AbstractDeviceParser::DEVICE_TYPE_PHABLET => UaProfile::DEVICE_MOBILE,
            AbstractDeviceParser::DEVICE_TYPE_TABLET => UaProfile::DEVICE_TABLET,
            default => UaProfile::DEVICE_UNKNOWN,
        };
    }

    private function os(DeviceDetector $detector): string
    {
        $family = $detector->getOs('family');

        if (! is_string($family) || $family === '') {
            $shortName = $detector->getOs('short_name');

            $family = is_string($shortName)
                ? (OperatingSystem::getOsFamily($shortName) ?? '')
                : '';
        }

        return match (true) {
            $family === 'Android' => UaProfile::OS_ANDROID,
            $family === 'iOS' => UaProfile::OS_IOS,
            str_contains($family, 'Windows') => UaProfile::OS_WINDOWS,
            $family === 'Mac' => UaProfile::OS_MACOS,
            $family === 'GNU/Linux' => UaProfile::OS_LINUX,
            default => UaProfile::OS_UNKNOWN,
        };
    }
}

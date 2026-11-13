<?php

namespace App\Modules\Statistics\Domain\Services;

/**
 * Server-side классификация ботов по User-Agent (ADR-0011): боты не
 * входят в visits/uniqueVisitors, но учитываются отдельным счётчиком
 * botRequests. Coarse-лист достаточен для дашборда; без внешних
 * UA-parser-зависимостей.
 */
final class BotClassifier
{
    private const PATTERNS = [
        'bot', 'crawler', 'spider', 'slurp', 'curl', 'wget', 'python-requests',
        'httpclient', ' okhttp', 'postman', 'insomnia', 'scrapy', 'phantomjs',
        'headlesschrome', 'lighthouse', 'pagespeed', 'uptime', 'pingdom',
        'monitor', 'checkly', 'site24x7', 'semrush', 'ahrefs', 'mj12bot',
        'dotbot', 'petalbot', 'bytespider', 'gptbot', 'claudebot', 'ccbot',
        'yandexbot', 'googlebot', 'bingbot', 'duckduckbot', 'baiduspider',
    ];

    public static function isBot(?string $userAgent): bool
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return true; // UA-головка отсутствует — типично для ботов/скриптов
        }

        $normalized = ' '.mb_strtolower($userAgent).' ';

        foreach (self::PATTERNS as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }
}

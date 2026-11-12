<?php

namespace App\Modules\Catalog\Infrastructure;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Скачивание картинок импорта (1С CommerceML) в storage. SSRF-hardening:
 * только http/https, запрет loopback/private/reserved IP (в т.ч. после
 * DNS-резолва хоста), без редиректов, таймаут и потолок размера.
 * Недетерминированные сбои — null (каталог не должен падать из-за картинки).
 */
final class ImportedImageFetcher
{
    private const int TIMEOUT_SECONDS = 15;

    /** Максимальный размер картинки, байт (10 МБ). */
    private const int MAX_BYTES = 10485760;

    /**
     * @param  \Closure(string): list<string>|null  $resolveHostIps  DNS-резолв хоста в IP (в тестах подменяется).
     */
    public function __construct(
        private readonly ?\Closure $resolveHostIps = null,
    ) {}

    public function fetchToStorage(string $url, string $storagePath): ?string
    {
        if (! $this->isAllowedUrl($url)) {
            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->withHeaders(['Accept' => 'image/*,*/*;q=0.8'])
                ->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $contentLength = (string) $response->header('Content-Length');

        if ($contentLength !== '' && (int) $contentLength > self::MAX_BYTES) {
            return null;
        }

        $body = $response->body();

        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            return null;
        }

        Storage::disk('local')->put($storagePath, $body);

        return $storagePath;
    }

    private function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
            return false;
        }

        $host = $parts['host'] ?? '';

        if ($host === '' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : $this->resolveIps($host);

        if ($ips === []) {
            return false;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function resolveIps(string $host): array
    {
        if ($this->resolveHostIps !== null) {
            return ($this->resolveHostIps)($host);
        }

        return gethostbynamel($host) ?: [];
    }
}

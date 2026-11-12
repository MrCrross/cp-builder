<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Базовый набор security-заголовков (план §9.3): CSP с per-request nonce
     * для скриптов сборки, без object/embed, без base-uri. Директивы
     * ужесточаются отдельным решением вместе с public-документом (этап 3).
     *
     * В local dev к connect-src добавляется ws: (HMR Vite), в script-src —
     * origin dev-сервера: скрипты Vite client подключаются с localhost:5173.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(16));

        Vite::useCspNonce($nonce);

        $response = $next($request);

        $isLocal = app()->environment('local');

        $csp = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'".($isLocal ? ' http://localhost:5173' : ''),
            "style-src 'self' 'unsafe-inline'".($isLocal ? ' http://localhost:5173' : ''),
            "img-src 'self' data:",
            "font-src 'self'".($isLocal ? ' http://localhost:5173' : ''),
            "connect-src 'self'".($isLocal ? ' ws:' : ''),
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ];

        $response->headers->set('Content-Security-Policy', implode('; ', $csp));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        return $response;
    }
}

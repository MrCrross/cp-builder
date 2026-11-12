<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    /**
     * Назначает каждому запросу UUID: попадает в контекст технических логов
     * (stdout JSON, план §9.6) и в audit-записи; отдаётся клиенту в заголовке
     * X-Request-Id для корреляции обращений.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);

        Log::shareContext(['request_id' => $requestId]);

        $response = $next($request);

        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}

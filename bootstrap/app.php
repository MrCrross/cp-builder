<?php

use App\Console\Commands\CreateAdministratorCommand;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware(
                config('fortify.middleware', ['web']),
            )->group(base_path('routes/fortify.php'));
        },
    )
    ->withCommands([
        CreateAdministratorCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(prepend: [
            AssignRequestId::class,
            SecurityHeaders::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Inertia-страницы HTTP-ошибок (403/404/…) вместо Symfony-HTML:
        // full page load рендерим страницу ошибки, X-Inertia-запросы и API
        // получают стандартный JSON ответ обработчика.
        $exceptions->render(function (HttpException $exception, Request $request) {
            if ($request->is('api/*') || $request->expectsJson() || $request->header('X-Inertia')) {
                return null;
            }

            $status = $exception->getStatusCode();

            if (! is_file(resource_path("js/src/pages/errors/{$status}.svelte"))) {
                return null;
            }

            return Inertia::render("errors/{$status}", [
                'status' => $status,
            ])->toResponse($request)->setStatusCode($status);
        });
    })->create();

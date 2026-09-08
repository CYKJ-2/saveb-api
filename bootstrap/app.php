<?php

use App\Exceptions\SystemException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global middleware — TraceId first, AccessLog second.
        $middleware->prepend(\App\Middleware\AccessLog::class);
        $middleware->prepend(\App\Middleware\TraceId::class);

        $middleware->alias([
            'auth.api'     => \App\Middleware\Authenticate::class,
            'role'         => \App\Middleware\RequireRoles::class,
            'permission'   => \App\Middleware\RequirePermissions::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // SystemException → unified JSON error response
        $exceptions->render(function (SystemException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return \App\Common\AppResponse::error(
                    $e->getApiCode(),
                    $e->getMessage(),
                    $e->getHttpStatus(),
                );
            }

            return null;
        });
    })->create();

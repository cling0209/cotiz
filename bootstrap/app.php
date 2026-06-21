<?php

use App\Http\Middleware\EnsureCompraAgilAnalisisAdmin;
use App\Http\Middleware\EnsureAgileBasicAuth;
use App\Http\Middleware\EnsureNotaApiBasicAuth;
use App\Http\Middleware\EnsureSuperAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->redirectGuestsTo(fn (Request $request) => route('admin.login'));
        $middleware->alias([
            'superadmin' => EnsureSuperAdmin::class,
            'compra-agil-analisis' => EnsureCompraAgilAnalisisAdmin::class,
            'agile.basic' => EnsureAgileBasicAuth::class,
            'nota.basic' => EnsureNotaApiBasicAuth::class,
        ]);
        $middleware->api(prepend: [
            HandleCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Admin usa fetch (Accept: application/json) en rutas web; sin esto devuelve 302/HTML
        // y el navegador puede fallar con "Failed to fetch" en subidas multipart (Docker local).
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

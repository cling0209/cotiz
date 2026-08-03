<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Si MAINTENANCE_MODE=true (o alias NEXT_PUBLIC_MAINTENANCE_MODE), muestra
 * la página de mantención en lugar de la app. /up queda libre para health checks.
 */
class EnsureMaintenanceMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.maintenance_mode')) {
            return $next($request);
        }

        if ($request->is('up')) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'La aplicación está en mantención. Estará disponible en unos minutos.',
            ], 503);
        }

        return response()
            ->view('maintenance', [], 503)
            ->header('Retry-After', '300');
    }
}

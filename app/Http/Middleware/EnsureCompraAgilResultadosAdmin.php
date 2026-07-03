<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompraAgilResultadosAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('cotiz.mercadopublico.resultados_admin_habilitado', true)) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Resultados Compra Ágil no habilitado en este servicio.'], 404);
            }

            abort(404);
        }

        $user = $request->user();
        if (! $user?->canAccessCompraAgilResultados()) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'No autorizado.'], 403);
            }

            abort(403);
        }

        return $next($request);
    }
}

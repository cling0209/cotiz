<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOportunidadesAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user?->canAccessOportunidades()) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'No autorizado.'], 403);
            }

            abort(403);
        }

        return $next($request);
    }
}

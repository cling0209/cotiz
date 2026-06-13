<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->perfil !== User::PERFIL_SUPERADMIN) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['error' => 'Acceso no autorizado.'], 403);
            }

            abort(403, 'Acceso restringido a superadministradores.');
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== 'admin') {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'data' => null,
                    'meta' => (object) [],
                    'errors' => ['message' => 'Acceso no autorizado.'],
                ], 403);
            }

            return redirect()
                ->route('admin.login')
                ->with('error', 'Debes iniciar sesión como administrador.');
        }

        return $next($request);
    }
}

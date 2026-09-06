<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->activo) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['error' => 'Usuario deshabilitado.'], 403);
            }

            return redirect()
                ->route('admin.login')
                ->with('error', 'Usuario deshabilitado. Contacte al administrador para habilitar el acceso.');
        }

        return $next($request);
    }
}

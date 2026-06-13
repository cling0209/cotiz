<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAgileBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = (string) config('cotiz.agile.user', '');
        $password = (string) config('cotiz.agile.password', '');

        if ($request->getUser() !== $user || $request->getPassword() !== $password) {
            return response()->json([
                'resultado' => 'ERROR',
                'mensaje' => 'Error en datos de autorizacion',
            ], 401);
        }

        return $next($request);
    }
}

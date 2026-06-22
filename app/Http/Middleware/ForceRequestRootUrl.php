<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * En producción, alinear route()/url() con el host real de la petición.
 * Evita 404 cuando APP_URL difiere del dominio de acceso (ej. cotiza.romulo.cl vs cotiz.romulo.cl).
 */
class ForceRequestRootUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('production') && $request->getHttpHost() !== '') {
            URL::forceScheme('https');
            URL::forceRootUrl('https://'.$request->getHttpHost());
        }

        return $next($request);
    }
}

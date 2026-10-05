<?php

namespace App\Http\Middleware;

use App\Services\CotizarIaService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOportunidadesViewer
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user?->canVerOportunidades()) {
            $puedeVerAdjuntoCotizarIa = CotizarIaService::usuarioPermitido($user)
                && $request->routeIs('admin.oportunidades.para-cotizar.adjuntos.ver');
            if (! $puedeVerAdjuntoCotizarIa) {
                if ($request->expectsJson()) {
                    return response()->json(['error' => 'No autorizado.'], 403);
                }

                abort(403);
            }
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Services\NotaMpResultadosService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Si el horario programado de consulta MP ya pasó y no hubo corrida,
 * encola catch-up al primer request web (p. ej. usuario que despierta Render).
 */
class EnsureMpResultadosScheduleCatchUp
{
    private const CACHE_KEY = 'mp_resultados_schedule_catchup_check';

    /** Evita golpear BD en cada asset/request: máximo 1 intento por minuto. */
    private const CACHE_TTL_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->debeIntentarCatchUp($request)) {
            $this->intentarCatchUp();
        }

        return $next($request);
    }

    private function debeIntentarCatchUp(Request $request): bool
    {
        if (! config('cotiz.mercadopublico.resultados_schedule_habilitado', true)) {
            return false;
        }

        if ($request->isMethod('OPTIONS')) {
            return false;
        }

        // Health y assets no cuentan como “usuario conectado”.
        if ($request->is('up', 'up/*')) {
            return false;
        }

        $path = ltrim($request->path(), '/');
        if ($path !== '' && preg_match('#^(css|js|images|img|fonts|favicon|storage|build|vendor)/#i', $path) === 1) {
            return false;
        }

        return Cache::add(self::CACHE_KEY, 1, self::CACHE_TTL_SECONDS);
    }

    private function intentarCatchUp(): void
    {
        try {
            $resultado = app(NotaMpResultadosService::class)
                ->asegurarCorridaProgramadaSiCorresponde('sistema');

            if (($resultado['accion'] ?? '') === 'encolada') {
                Log::info('Catch-up MP encolado por request web', $resultado);
            }
        } catch (Throwable $e) {
            Log::warning('Catch-up MP por request web falló', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}

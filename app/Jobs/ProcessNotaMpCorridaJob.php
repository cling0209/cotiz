<?php

namespace App\Jobs;

use App\Models\NotaMpCorrida;
use App\Services\NotaMpResultadosService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessNotaMpCorridaJob implements ShouldQueue
{
    use Queueable;

    /** Tiempo máximo por ejecución (pipeline de la corrida). */
    public int $timeout = 43200;

    public int $maxExceptions = 3;

    public function retryUntil(): \DateTime
    {
        return now()->addHours(12);
    }

    public function __construct(
        public int $corridaId,
    ) {
        $maxSegNota = max(60, (int) config('cotiz.mercadopublico.resultados_nota_max_segundos', 180));
        $timeoutApi = max(15, (int) config('cotiz.mercadopublico.api_timeout_segundos', 45));
        $staggerMs = max(0, (int) config('cotiz.mercadopublico.resultados_stagger_ms', 2000));
        // Pipeline largo: stagger * notas + timeouts API. Render worker ya usa --timeout=43200.
        $this->timeout = max(3600, $maxSegNota + ($timeoutApi * 3) + (int) ceil($staggerMs / 1000) * 200);
    }

    public function handle(NotaMpResultadosService $resultados): void
    {
        $corrida = NotaMpCorrida::query()->find($this->corridaId);
        if ($corrida === null || $corrida->estado !== 'running') {
            return;
        }

        // Si quedó pegada con detalle ya guardado, avanza el índice y limpia "en curso".
        $resultados->avanzarIndiceSiDetalleYaExiste($corrida);
        $corrida->refresh();

        $pendientes = is_array($corrida->pendientes_json) ? $corrida->pendientes_json : [];
        $indice = (int) $corrida->notas_procesadas;
        $restantes = max(0, count($pendientes) - $indice);

        if ($restantes <= 0) {
            $this->finalizarSiCompleta($resultados, $corrida);

            return;
        }

        // Pipeline continuo: todas las pendientes, disparo cada ~2 s, máx. N en vuelo.
        $lote = $resultados->lotePendienteActual($corrida, $restantes);
        if ($lote === []) {
            // Ítem actual ya tiene detalle o es inválido: saltar uno y continuar.
            $corrida->increment('notas_procesadas');
            $this->encolarSiguiente($resultados, $corrida->fresh() ?? $corrida);

            return;
        }

        $resultadoLote = $resultados->consultarLoteMasivo(
            $corrida,
            $lote,
            (string) $corrida->usuario,
        );

        $corrida->refresh();
        if ($corrida->estado !== 'running') {
            return;
        }

        $this->encolarSiguiente($resultados, $corrida, $resultadoLote['ultimo_error'] ?? null);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('ProcessNotaMpCorridaJob failed', [
            'corrida_id' => $this->corridaId,
            'message' => $exception?->getMessage(),
        ]);

        $resultados = app(NotaMpResultadosService::class);
        $corrida = NotaMpCorrida::query()->find($this->corridaId);
        if ($corrida === null || $corrida->estado !== 'running') {
            return;
        }

        $enCurso = $resultados->enCursoActual($corrida);
        if ($enCurso === []) {
            $enCurso = array_slice($resultados->lotePendienteActual($corrida, 1), 0, 1);
        }
        $msg = $this->mensajeAmigable($exception);

        foreach ($enCurso as $item) {
            $nronota = (int) ($item['nronota'] ?? 0);
            $codigo = trim((string) ($item['codigo'] ?? ''));
            if ($nronota <= 0) {
                continue;
            }
            $resultados->registrarDetalleFallo(
                $corrida,
                $nronota,
                $codigo,
                mb_substr($msg.' (job interrumpido)', 0, 500),
                null,
            );
            $corrida->increment('notas_procesadas');
        }

        if ($enCurso === []) {
            $corrida->increment('notas_procesadas');
        }

        $corrida->refresh();
        if ($corrida->estado !== 'running') {
            return;
        }

        try {
            $this->encolarSiguiente($resultados, $corrida, $exception?->getMessage());
        } catch (\Throwable $e) {
            $resultados->finalizarCorrida(
                $corrida,
                'error',
                'Consulta interrumpida: '.$e->getMessage(),
            );
        }
    }

    private function encolarSiguiente(
        NotaMpResultadosService $resultados,
        NotaMpCorrida $corrida,
        ?string $ultimoError = null,
    ): void {
        $corrida->refresh();
        $pendientes = is_array($corrida->pendientes_json) ? $corrida->pendientes_json : [];
        $procesadas = (int) $corrida->notas_procesadas;

        if ($procesadas >= count($pendientes)) {
            $resultados->marcarSiguienteNotaPendiente($corrida);
            $this->finalizarSiCompleta($resultados, $corrida, $ultimoError);

            return;
        }

        $resultados->marcarSiguienteNotaPendiente($corrida);

        if ($resultados->jobResultadosMpEncolado($corrida->id)) {
            return;
        }

        $delayMs = max(0, (int) config('cotiz.mercadopublico.resultados_delay_ms', 350));
        $job = self::dispatch($corrida->id);
        if ($delayMs > 0) {
            $job->delay(now()->addMilliseconds($delayMs));
        }
    }

    private function finalizarSiCompleta(
        NotaMpResultadosService $resultados,
        NotaMpCorrida $corrida,
        ?string $ultimoError = null,
    ): void {
        $corrida->refresh();
        if ($corrida->estado !== 'running') {
            return;
        }

        $fallidas = (int) \App\Models\NotaMpCorridaDetalle::query()
            ->where('corrida_id', $corrida->id)
            ->whereRaw('exito IS FALSE')
            ->count();

        $resultados->finalizarCorridaDesdeJob(
            $corrida->fresh() ?? $corrida,
            $fallidas,
            $ultimoError,
        );
    }

    private function mensajeAmigable(?Throwable $exception): string
    {
        $msg = $exception?->getMessage() ?? '';

        if (str_contains($msg, 'attempted too many times')) {
            return 'La consulta fue interrumpida por el servidor. Se continuará con la siguiente nota.';
        }
        if (str_contains($msg, 'has timed out') || str_contains($msg, 'Maximum execution time')) {
            return NotaMpResultadosService::mensajeTiempoMaximoNota();
        }

        return $msg ?: 'La consulta en segundo plano falló.';
    }
}

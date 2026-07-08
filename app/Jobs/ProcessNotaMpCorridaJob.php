<?php

namespace App\Jobs;

use App\Models\NotaMpCorrida;
use App\Services\CompraAgilApiService;
use App\Services\NotaMpResultadosService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessNotaMpCorridaJob implements ShouldQueue
{
    use Queueable;

    /** Tiempo máximo por ejecución (una nota). */
    public int $timeout = 300;

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
        $reintentos = max(1, (int) config('cotiz.mercadopublico.api_reintentos_http', 3));
        $esperaReintento = max(1, (int) config('cotiz.mercadopublico.api_espera_reintento_seg', 5));
        $margenJob = max(90, ($timeoutApi + $esperaReintento) * $reintentos + 60);

        $this->timeout = max($maxSegNota + 90, $margenJob);
    }

    public function handle(NotaMpResultadosService $resultados): void
    {
        $corrida = NotaMpCorrida::query()->find($this->corridaId);
        if ($corrida === null || $corrida->estado !== 'running') {
            return;
        }

        $pendientes = is_array($corrida->pendientes_json) ? $corrida->pendientes_json : [];
        $indice = (int) $corrida->notas_procesadas;

        if ($indice >= count($pendientes)) {
            $this->finalizarSiCompleta($resultados, $corrida);

            return;
        }

        $item = $pendientes[$indice];
        if (! is_array($item)) {
            $corrida->increment('notas_procesadas');
            $this->encolarSiguiente($resultados, $corrida->fresh() ?? $corrida);

            return;
        }

        $nronota = (int) ($item['nronota'] ?? 0);
        $codigo = trim((string) ($item['codigo'] ?? ''));
        if ($nronota <= 0) {
            $corrida->increment('notas_procesadas');
            $this->encolarSiguiente($resultados, $corrida->fresh() ?? $corrida);

            return;
        }

        $resultados->marcarNotaEnConsulta($corrida, $nronota, $codigo);

        // Sin deadline: misma resiliencia que «Consultar MP» individual (reintentos HTTP + timeout completo).
        // El tope de seguridad es $this->timeout del job (resultados_nota_max_segundos + margen).
        $ultimoError = null;
        $exito = false;
        $intentosHttp = max(1, (int) config('cotiz.mercadopublico.api_reintentos_http', 3));

        try {
            $resultados->consultarNota($nronota, $corrida, (string) $corrida->usuario, null);
            $exito = true;
        } catch (\Throwable $e) {
            $ultimoError = $e->getMessage();
            Log::warning('ProcessNotaMpCorridaJob: nota fallida', [
                'corrida_id' => $corrida->id,
                'nronota' => $nronota,
                'codigo' => $codigo,
                'message' => $ultimoError,
                'intentos_http' => $intentosHttp,
            ]);
        }

        $corrida->refresh();
        if ((int) $corrida->notas_procesadas <= $indice) {
            if (! $exito) {
                $empresa = trim((string) ($item['empresa'] ?? ''));
                $sufijoIntentos = CompraAgilApiService::esErrorDefinitivoMp((string) $ultimoError)
                    ? 'sin reintento'
                    : $intentosHttp.' intentos HTTP';
                $resultados->registrarDetalleFallo(
                    $corrida,
                    $nronota,
                    $codigo,
                    mb_substr(
                        ($ultimoError ?: 'Error desconocido').' ('.$sufijoIntentos.')',
                        0,
                        500,
                    ),
                    $empresa !== '' ? $empresa : null,
                );
            }
            $corrida->increment('notas_procesadas');
        }

        $corrida->refresh();
        if ($corrida->estado !== 'running') {
            return;
        }

        $this->encolarSiguiente($resultados, $corrida, $ultimoError);
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

        $pendientes = is_array($corrida->pendientes_json) ? $corrida->pendientes_json : [];
        $indice = (int) $corrida->notas_procesadas;

        if ($indice < count($pendientes)) {
            $item = $pendientes[$indice];
            if (is_array($item)) {
                $nronota = (int) ($item['nronota'] ?? 0);
                $codigo = trim((string) ($item['codigo'] ?? ''));
                if ($nronota > 0) {
                    $msg = $this->mensajeAmigable($exception);
                    $empresa = trim((string) ($item['empresa'] ?? ''));
                    $resultados->registrarDetalleFallo(
                        $corrida,
                        $nronota,
                        $codigo,
                        mb_substr($msg.' (job interrumpido)', 0, 500),
                        $empresa !== '' ? $empresa : null,
                    );
                }
            }
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

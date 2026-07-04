<?php

namespace App\Jobs;

use App\Models\NotaMpCorrida;
use App\Services\NotaMpResultadosService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ProcessNotaMpCorridaJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public int $corridaId,
    ) {}

    public function handle(NotaMpResultadosService $resultados): void
    {
        $corrida = NotaMpCorrida::query()->find($this->corridaId);
        if ($corrida === null || $corrida->estado !== 'running') {
            return;
        }

        $pendientes = is_array($corrida->pendientes_json) ? $corrida->pendientes_json : [];
        $procesadasPrevias = (int) $corrida->notas_procesadas;
        if ($procesadasPrevias > 0) {
            $pendientes = array_slice($pendientes, $procesadasPrevias);
        }

        $delayMs = max(0, (int) config('cotiz.mercadopublico.resultados_delay_ms', 350));
        $fallidas = 0;
        $ultimoError = null;

        foreach ($pendientes as $item) {
            $corrida->refresh();
            if ($corrida->estado !== 'running') {
                return;
            }

            if (! is_array($item)) {
                $corrida->increment('notas_procesadas');

                continue;
            }

            $nronota = (int) ($item['nronota'] ?? 0);
            $codigo = trim((string) ($item['codigo'] ?? ''));
            if ($nronota <= 0) {
                $corrida->increment('notas_procesadas');

                continue;
            }

            $corrida->update([
                'nronota_actual' => $nronota,
                'codigo_actual' => $codigo !== '' ? $codigo : null,
            ]);

            try {
                $resultados->consultarNota($nronota, $corrida, (string) $corrida->usuario);
            } catch (\Throwable $e) {
                $fallidas++;
                $ultimoError = $e->getMessage();
                $empresa = trim((string) ($item['empresa'] ?? ''));
                $resultados->registrarDetalleFallo(
                    $corrida,
                    $nronota,
                    $codigo,
                    mb_substr($ultimoError, 0, 500),
                    $empresa !== '' ? $empresa : null,
                );
                Log::warning('ProcessNotaMpCorridaJob: nota omitida', [
                    'corrida_id' => $corrida->id,
                    'nronota' => $nronota,
                    'codigo' => $codigo,
                    'message' => $ultimoError,
                ]);
                $corrida->increment('notas_procesadas');
            }

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $corrida->refresh();
        if ($corrida->estado !== 'running') {
            return;
        }

        $corrida->update([
            'nronota_actual' => null,
            'codigo_actual' => null,
        ]);

        $resultados->finalizarCorridaDesdeJob(
            $corrida->fresh() ?? $corrida,
            $fallidas,
            $ultimoError,
        );
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('ProcessNotaMpCorridaJob failed', [
            'corrida_id' => $this->corridaId,
            'message' => $exception?->getMessage(),
        ]);

        $corrida = NotaMpCorrida::query()->find($this->corridaId);
        if ($corrida === null || $corrida->estado !== 'running') {
            return;
        }

        app(NotaMpResultadosService::class)->finalizarCorrida(
            $corrida,
            'error',
            $exception?->getMessage() ?: 'La consulta en segundo plano falló.',
        );
    }
}

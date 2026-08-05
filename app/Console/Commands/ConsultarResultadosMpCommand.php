<?php

namespace App\Console\Commands;

use App\Services\NotaMpResultadosService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class ConsultarResultadosMpCommand extends Command
{
    protected $signature = 'compra-agil:consultar-resultados
                            {--usuario=sistema : Usuario registrado en la corrida}
                            {--catch-up : Solo encola si el último horario programado se pasó sin corrida (boot Render)}';

    protected $description = 'Encola la consulta masiva a Mercado Público (equivalente a «Consultar ahora»)';

    public function handle(NotaMpResultadosService $resultados): int
    {
        $usuario = trim((string) $this->option('usuario')) ?: 'sistema';

        if ($this->option('catch-up')) {
            return $this->handleCatchUp($resultados, $usuario);
        }

        if (! $resultados->apiConfigurada()) {
            $this->warn('MERCADOPUBLICO_TICKET no configurado. Se omite la consulta programada.');

            return self::SUCCESS;
        }

        if ($resultados->corridaEnCurso() !== null) {
            $this->warn('Ya hay una consulta en curso. Se omite la corrida programada.');

            return self::SUCCESS;
        }

        try {
            $corrida = $resultados->encolarCorrida($usuario);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            if (
                str_contains($msg, 'No hay cotizaciones pendientes')
                || str_contains($msg, 'Ya hay una consulta en curso')
            ) {
                $this->info($msg);
                // Sin corrida de resultados: continúa el pipeline (búsqueda → …).
                if (str_contains($msg, 'No hay cotizaciones pendientes')) {
                    $this->continuarPipeline($resultados, $usuario);
                }

                return self::SUCCESS;
            }

            $this->error($msg);

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Consulta encolada (corrida #%d, %d nota(s), usuario %s).',
            $corrida->id,
            (int) $corrida->total_notas,
            $usuario,
        ));

        return self::SUCCESS;
    }

    private function continuarPipeline(NotaMpResultadosService $resultados, string $usuario): void
    {
        $pipeline = $resultados->continuarPipelineOportunidadesTrasResultados($usuario);
        $accion = (string) ($pipeline['accion'] ?? '');
        $mensaje = (string) ($pipeline['mensaje'] ?? '');
        if ($accion === 'encolada') {
            $this->info('Pipeline: '.$mensaje.' (corrida #'.($pipeline['corrida_id'] ?? '?').').');
        } elseif ($mensaje !== '') {
            $this->info('Pipeline: '.$mensaje);
        }
    }

    private function handleCatchUp(NotaMpResultadosService $resultados, string $usuario): int
    {
        try {
            $resultado = $resultados->asegurarCorridaProgramadaSiCorresponde(
                $usuario,
                NotaMpResultadosService::CATCHUP_ORIGEN_BOOT,
            );
        } catch (Throwable $e) {
            $this->error('Catch-up falló: '.$e->getMessage());

            return self::FAILURE;
        }

        $mensaje = (string) ($resultado['mensaje'] ?? $resultado['accion']);
        $accion = (string) ($resultado['accion'] ?? '');
        if ($accion === 'encolada') {
            $this->info($mensaje.' (corrida #'.($resultado['corrida_id'] ?? '?').').');
        } else {
            $this->info($mensaje);
            // Si no hay corrida de resultados en curso/encolada, sigue con búsqueda.
            if (! str_contains($mensaje, 'en curso')) {
                $this->continuarPipeline($resultados, $usuario);
            }
        }

        return self::SUCCESS;
    }
}

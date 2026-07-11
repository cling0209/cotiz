<?php

namespace App\Console\Commands;

use App\Services\NotaMpResultadosService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class ConsultarResultadosMpCommand extends Command
{
    protected $signature = 'compra-agil:consultar-resultados
                            {--usuario=sistema : Usuario registrado en la corrida}';

    protected $description = 'Encola la consulta masiva a Mercado Público (equivalente a «Consultar ahora»)';

    public function handle(NotaMpResultadosService $resultados): int
    {
        if (! $resultados->apiConfigurada()) {
            $this->warn('MERCADOPUBLICO_TICKET no configurado. Se omite la consulta programada.');

            return self::SUCCESS;
        }

        if ($resultados->corridaEnCurso() !== null) {
            $this->warn('Ya hay una consulta en curso. Se omite la corrida programada.');

            return self::SUCCESS;
        }

        $usuario = trim((string) $this->option('usuario')) ?: 'sistema';

        try {
            $corrida = $resultados->encolarCorrida($usuario);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            if (
                str_contains($msg, 'No hay cotizaciones pendientes')
                || str_contains($msg, 'Ya hay una consulta en curso')
            ) {
                $this->info($msg);

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
}

<?php

namespace App\Console\Commands;

use App\Services\NotaMpResultadosService;
use Illuminate\Console\Command;

class CerrarSeguimientosOcCommand extends Command
{
    protected $signature = 'compra-agil:cerrar-seguimientos-oc
                            {--limit=500 : Máximo de seguimientos a revisar}
                            {--dry-run : Lista los que se cerrarían sin modificar}';

    protected $description = 'Deja de consultar MP en seguimientos con OC entregada a otra empresa o código OC no encontrado';

    public function handle(NotaMpResultadosService $resultados): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $cerrados = $resultados->cerrarSeguimientosOcResueltos($dryRun, max(1, (int) $this->option('limit')));

        foreach ($cerrados as $fila) {
            $this->line(sprintf(
                '  nronota=%d cot=%s → %s%s',
                $fila['nronota'],
                $fila['codigo_proceso'],
                $fila['estado']->etiqueta(),
                $fila['razon_social_ganador'] !== '' ? ' ('.$fila['razon_social_ganador'].')' : '',
            ));
        }

        $this->info(sprintf(
            '%s: %d',
            $dryRun ? 'Se cerrarían (dry-run)' : 'Cerrados',
            count($cerrados),
        ));

        return self::SUCCESS;
    }
}

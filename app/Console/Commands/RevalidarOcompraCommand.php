<?php

namespace App\Console\Commands;

use App\Models\NotaMpSeguimiento;
use App\Services\NotaMpResultadosService;
use Illuminate\Console\Command;

class RevalidarOcompraCommand extends Command
{
    protected $signature = 'compra-agil:revalidar-ocompra
                            {--limit=200 : Máximo de seguimientos a revisar}
                            {--delay-ms=500 : Pausa entre llamadas a MP (cuota)}
                            {--nronota= : Solo esta nota}
                            {--aplicar : Borra ocompra_mp y fechas OC de los que no coinciden}
                            {--limpiar-nota : Con --aplicar, borra también notas.ocompra si es el mismo código}
                            {--todos : Lista también los que coinciden}';

    protected $description = 'Revisa que el código OC de MP (ocompra_mp) corresponda a la cotización: COT en la OC o total = monto ganado';

    public function handle(NotaMpResultadosService $resultados): int
    {
        if (! $resultados->apiConfigurada()) {
            $this->error('MERCADOPUBLICO_TICKET no configurado.');

            return self::FAILURE;
        }

        $aplicar = (bool) $this->option('aplicar');
        $limpiarNota = $aplicar && (bool) $this->option('limpiar-nota');
        $delayMs = max(0, (int) $this->option('delay-ms'));
        $nronotaOpt = $this->option('nronota');

        $query = NotaMpSeguimiento::query()
            ->whereRaw("trim(coalesce(ocompra_mp, '')) <> ''")
            ->orderByDesc('nronota');

        if ($nronotaOpt !== null && $nronotaOpt !== '') {
            $query->where('nronota', (int) $nronotaOpt);
        }

        $nronotas = $query->limit(max(1, (int) $this->option('limit')))->pluck('nronota');
        if ($nronotas->isEmpty()) {
            $this->info('No hay seguimientos con ocompra_mp.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Revisando %d seguimientos (%s).',
            $nronotas->count(),
            $aplicar ? ($limpiarNota ? 'aplicar + limpiar nota' : 'aplicar') : 'solo listado',
        ));

        $conteo = [];
        $filas = [];
        foreach ($nronotas as $i => $nronota) {
            $r = $resultados->revalidarOcompraMp((int) $nronota, $aplicar, $limpiarNota);
            $conteo[$r['resultado']] = ($conteo[$r['resultado']] ?? 0) + 1;

            if ($r['resultado'] === 'error_cuota') {
                $this->error('Cuota MP agotada; se detiene la revisión.');

                break;
            }

            $ok = in_array($r['resultado'], ['ok_cot', 'ok_monto'], true);
            if (! $ok || $this->option('todos')) {
                $filas[] = [
                    $nronota,
                    $r['codigo_cot'],
                    $r['ocompra_mp'],
                    $r['ocompra_nota'] ?: '—',
                    $r['total_oc'] !== null ? number_format($r['total_oc'], 0, ',', '.') : '—',
                    $r['monto_ganador'] !== null ? number_format($r['monto_ganador'], 0, ',', '.') : '—',
                    $r['resultado'].($r['nota_limpiada'] ? ' (nota limpiada)' : ''),
                ];
            }

            if ($delayMs > 0 && $i < $nronotas->count() - 1) {
                usleep($delayMs * 1000);
            }
        }

        if ($filas !== []) {
            $this->table(['Nota', 'COT', 'OC MP', 'OC nota', 'Total OC', 'Monto ganado', 'Resultado'], $filas);
        }

        ksort($conteo);
        $this->info('Resumen: '.collect($conteo)->map(fn ($n, $k) => "{$k}={$n}")->implode(' '));

        if (! $aplicar && ($conteo['no_coincide'] ?? 0) > 0) {
            $this->warn('Sin cambios. Use --aplicar para borrar los códigos MP que no coinciden'
                .' (y --limpiar-nota para borrarlos también de la nota).'
                .' Luego compra-agil:backfill-ocompra los vuelve a buscar.');
        }

        return self::SUCCESS;
    }
}

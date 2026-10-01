<?php

namespace App\Console\Commands;

use App\Models\Nota;
use App\Models\NotaMpSeguimiento;
use App\Services\NotaMpResultadosService;
use Illuminate\Console\Command;

class BackfillOcompraCommand extends Command
{
    protected $signature = 'compra-agil:backfill-ocompra
                            {--limit=100 : Máximo de notas a procesar}
                            {--delay-ms=800 : Pausa entre llamadas a MP (cuota)}
                            {--radio-dias=3 : Días ± alrededor de fecha_ultimo_cambio}
                            {--nronota= : Solo esta nota}
                            {--dry-run : Lista candidatas sin llamar a MP}';

    protected $description = 'Resuelve el código OC (AG) de MP en seguimientos cerrados de la empresa propia sin ocompra_mp';

    public function handle(NotaMpResultadosService $resultados): int
    {
        $radio = max(0, min(7, (int) $this->option('radio-dias')));
        config(['cotiz.mercadopublico.oc_backfill_radio_dias' => $radio]);

        if (! $resultados->apiConfigurada() && ! $this->option('dry-run')) {
            $this->error('MERCADOPUBLICO_TICKET no configurado.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $delayMs = max(0, (int) $this->option('delay-ms'));
        $nronotaOpt = $this->option('nronota');

        $rutPropio = strtoupper(preg_replace('/[^0-9kK]/', '', (string) config('cotiz.empresa_rut', '')) ?? '');

        // Solo cerradas de la empresa propia, no aceptadas a mano, con OC ya emitida y sin código OC.
        $query = Nota::query()
            ->select([
                'notas.nronota',
                'notas.ocompra',
                'notas.encargado',
                'seg.id_orden_compra',
                'seg.codigo_proceso',
                'seg.rut_ganador',
            ])
            ->join('nota_mp_seguimientos as seg', 'seg.nronota', '=', 'notas.nronota')
            ->where('seg.resultado_propio', 'cerrada')
            ->whereRaw("lower(trim(coalesce(notas.estado, ''))) <> 'aceptada'")
            ->whereRaw("trim(coalesce(seg.ocompra_mp, '')) = ''")
            ->whereNotNull('seg.id_orden_compra')
            ->where('seg.id_orden_compra', '>', 0)
            ->whereRaw("coalesce(seg.estado_mp_codigo, '') <> 'proveedor_seleccionado'")
            ->orderByDesc('notas.nronota');

        if ($rutPropio === '') {
            $this->warn('Sin COTIZ_EMPRESA_RUT configurado; no hay candidatas.');

            return self::SUCCESS;
        }

        $query->whereRaw(
            "regexp_replace(upper(coalesce(seg.rut_ganador, '')), '[^0-9K]', '', 'g') = ?",
            [$rutPropio],
        );

        if ($nronotaOpt !== null && $nronotaOpt !== '') {
            $query->where('notas.nronota', (int) $nronotaOpt);
        }

        $candidatas = $query->limit($limit)->get();

        if ($candidatas->isEmpty()) {
            $this->info('No hay notas cerradas propias con id_orden_compra y ocompra_mp vacío.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Candidatas: %d (limit=%d radio_dias=±%d; solo ganador propio)',
            $candidatas->count(),
            $limit,
            $radio,
        ));

        if ($this->option('dry-run')) {
            foreach ($candidatas as $nota) {
                $this->line(sprintf(
                    '  nronota=%d id_oc=%s cot=%s rut=%s',
                    $nota->nronota,
                    (string) ($nota->id_orden_compra ?? ''),
                    trim((string) ($nota->codigo_proceso ?: $nota->encargado ?: '')),
                    trim((string) ($nota->rut_ganador ?? '')),
                ));
            }

            return self::SUCCESS;
        }

        $ok = 0;
        $skip = 0;
        $notFound = 0;
        $errors = 0;

        foreach ($candidatas as $i => $nota) {
            $idOc = (string) ($nota->id_orden_compra ?? '');
            $resultado = $resultados->rellenarOcompraDesdeIdOrdenCompra((int) $nota->nronota);

            match ($resultado) {
                'updated' => $ok++,
                'skipped' => $skip++,
                'not_found' => $notFound++,
                'error_cuota' => null,
                default => $errors++,
            };

            $ocompra = $resultado === 'updated'
                ? trim((string) (NotaMpSeguimiento::query()->whereKey($nota->nronota)->value('ocompra_mp') ?? ''))
                : '';

            $this->line(sprintf(
                '[%d/%d] nronota=%d id_oc=%s → %s%s',
                $i + 1,
                $candidatas->count(),
                $nota->nronota,
                $idOc,
                $resultado,
                $ocompra !== '' ? ' ocompra='.$ocompra : '',
            ));

            if ($resultado === 'error_cuota') {
                $this->error('Cuota MP agotada; se detiene el backfill.');

                break;
            }

            if ($delayMs > 0 && $i < $candidatas->count() - 1) {
                usleep($delayMs * 1000);
            }
        }

        $this->info(sprintf(
            'Listo. updated=%d skipped=%d not_found=%d errors=%d',
            $ok,
            $skip,
            $notFound,
            $errors,
        ));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}

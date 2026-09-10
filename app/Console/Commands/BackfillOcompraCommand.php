<?php

namespace App\Console\Commands;

use App\Models\Nota;
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

    protected $description = 'Copia código OC (AG) a notas cerradas sin ocompra (listados OC v1 por fecha/COT)';

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

        // Solo cerradas en MP sin código OC alfanumérico (aunque tengan id_orden_compra).
        $query = Nota::query()
            ->select(['notas.nronota', 'notas.ocompra', 'notas.encargado', 'seg.id_orden_compra', 'seg.codigo_proceso'])
            ->join('nota_mp_seguimientos as seg', 'seg.nronota', '=', 'notas.nronota')
            ->where('seg.resultado_propio', 'cerrada')
            ->whereRaw("trim(coalesce(notas.ocompra, '')) = ''")
            ->whereNotNull('seg.id_orden_compra')
            ->where('seg.id_orden_compra', '>', 0)
            ->orderByDesc('notas.nronota');

        if ($nronotaOpt !== null && $nronotaOpt !== '') {
            $query->where('notas.nronota', (int) $nronotaOpt);
        }

        $candidatas = $query->limit($limit)->get();

        if ($candidatas->isEmpty()) {
            $this->info('No hay notas cerradas con id_orden_compra y ocompra vacío.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Candidatas: %d (limit=%d radio_dias=±%d)', $candidatas->count(), $limit, $radio));

        if ($this->option('dry-run')) {
            foreach ($candidatas as $nota) {
                $this->line(sprintf(
                    '  nronota=%d id_oc=%s cot=%s',
                    $nota->nronota,
                    (string) ($nota->id_orden_compra ?? ''),
                    trim((string) ($nota->codigo_proceso ?: $nota->encargado ?: '')),
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
                ? trim((string) (Nota::query()->whereKey($nota->nronota)->value('ocompra') ?? ''))
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

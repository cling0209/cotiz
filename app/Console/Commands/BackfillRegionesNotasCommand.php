<?php

namespace App\Console\Commands;

use App\Models\Nota;
use App\Services\NotaMpResultadosService;
use Illuminate\Console\Command;

class BackfillRegionesNotasCommand extends Command
{
    protected $signature = 'compra-agil:backfill-regiones
                            {--limit=100 : Máximo de notas a procesar}
                            {--delay-ms=800 : Pausa entre llamadas a MP (cuota)}
                            {--nronota= : Solo esta nota}
                            {--solo-con-oc : Solo notas con orden de compra (comisiones)}
                            {--dry-run : Lista candidatas sin llamar a MP}';

    protected $description = 'Rellena notas.region / nombre_region cuando faltan (proceso local, oportunidad o detalle MP)';

    public function handle(NotaMpResultadosService $resultados): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $delayMs = max(0, (int) $this->option('delay-ms'));
        $nronotaOpt = $this->option('nronota');

        $query = Nota::query()
            ->select(['notas.nronota', 'notas.encargado', 'notas.ocompra', 'notas.region', 'notas.nombre_region'])
            ->where(function ($q) {
                $q->whereNull('notas.region')
                    ->orWhere('notas.region', '<=', 0)
                    ->orWhereNull('notas.nombre_region')
                    ->orWhereRaw("trim(coalesce(notas.nombre_region, '')) = ''");
            })
            ->whereRaw("trim(coalesce(notas.encargado, '')) <> ''")
            ->orderByDesc('notas.nronota');

        if ($this->option('solo-con-oc')) {
            $query->whereRaw("trim(coalesce(notas.ocompra, '')) <> ''");
        }

        if ($nronotaOpt !== null && $nronotaOpt !== '') {
            $query->where('notas.nronota', (int) $nronotaOpt);
        }

        $candidatas = $query->limit($limit)->get();

        if ($candidatas->isEmpty()) {
            $this->info('No hay notas pendientes de región.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Candidatas: %d (limit=%d)', $candidatas->count(), $limit));

        if ($this->option('dry-run')) {
            foreach ($candidatas as $nota) {
                $this->line(sprintf(
                    '  nronota=%d encargado=%s region=%s nombre=%s',
                    $nota->nronota,
                    trim((string) $nota->encargado),
                    $nota->region ?? 'null',
                    trim((string) ($nota->nombre_region ?? '')) ?: '(vacío)',
                ));
            }

            return self::SUCCESS;
        }

        $ok = 0;
        $skip = 0;
        $notFound = 0;
        $errors = 0;

        foreach ($candidatas as $i => $nota) {
            $resultado = $resultados->rellenarRegionSiFalta((int) $nota->nronota);

            match ($resultado) {
                'updated' => $ok++,
                'skipped' => $skip++,
                'not_found' => $notFound++,
                'error_cuota' => null,
                default => $errors++,
            };

            $this->line(sprintf(
                '[%d/%d] nronota=%d %s → %s',
                $i + 1,
                $candidatas->count(),
                $nota->nronota,
                trim((string) $nota->encargado),
                $resultado,
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

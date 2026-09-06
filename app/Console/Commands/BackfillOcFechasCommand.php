<?php

namespace App\Console\Commands;

use App\Models\Nota;
use App\Services\NotaMpResultadosService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class BackfillOcFechasCommand extends Command
{
    protected $signature = 'compra-agil:backfill-oc-fechas
                            {--limit=100 : Máximo de notas a procesar}
                            {--delay-ms=800 : Pausa entre llamadas a MP (cuota)}
                            {--nronota= : Solo esta nota}
                            {--dry-run : Lista candidatas sin llamar a MP}
                            {--limpiar-ajenos : Borra ocompra/fechas si el ganador no es Reicol ni Romulo}';

    protected $description = 'Rellena oc_fecha_* (solo ganadores Reicol/Romulo) o limpia OC de ganadores ajenos';

    public function handle(NotaMpResultadosService $resultados): int
    {
        if ($this->option('limpiar-ajenos')) {
            return $this->handleLimpiarAjenos($resultados);
        }

        if (! Schema::hasColumn('nota_mp_seguimientos', 'oc_fecha_envio')) {
            $this->error('Falta migración oc_fecha_* (php artisan migrate).');

            return self::FAILURE;
        }

        if (! $resultados->apiConfigurada() && ! $this->option('dry-run')) {
            $this->error('MERCADOPUBLICO_TICKET no configurado.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $delayMs = max(0, (int) $this->option('delay-ms'));
        $nronotaOpt = $this->option('nronota');
        $rutsGrupo = array_values(array_filter([
            preg_replace('/[^0-9kK]/', '', (string) config('cotiz.reicol_rut', '')) ?? '',
            preg_replace('/[^0-9kK]/', '', (string) config('cotiz.romulo_rut', '')) ?? '',
        ]));
        $rutsGrupo = array_map('strtoupper', $rutsGrupo);

        $query = Nota::query()
            ->select(['notas.nronota', 'notas.ocompra', 'seg.rut_ganador'])
            ->join('nota_mp_seguimientos as seg', 'seg.nronota', '=', 'notas.nronota')
            ->whereRaw("trim(coalesce(notas.ocompra, '')) <> ''")
            ->where(function ($q) {
                $q->whereNull('seg.oc_fecha_envio')
                    ->orWhereNull('seg.oc_fecha_creacion');
            })
            ->orderByDesc('notas.nronota');

        if ($rutsGrupo !== []) {
            $placeholders = implode(', ', array_fill(0, count($rutsGrupo), '?'));
            $query->whereRaw(
                "regexp_replace(upper(coalesce(seg.rut_ganador, '')), '[^0-9K]', '', 'g') IN ({$placeholders})",
                $rutsGrupo,
            );
        }

        if ($nronotaOpt !== null && $nronotaOpt !== '') {
            $query->where('notas.nronota', (int) $nronotaOpt);
        }

        $candidatas = $query->limit($limit)->get();

        if ($candidatas->isEmpty()) {
            $this->info('No hay notas Reicol/Romulo con ocompra pendiente de fechas OC.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Candidatas Reicol/Romulo: %d (limit=%d)', $candidatas->count(), $limit));

        if ($this->option('dry-run')) {
            foreach ($candidatas as $nota) {
                $this->line(sprintf(
                    '  nronota=%d ocompra=%s rut_ganador=%s',
                    $nota->nronota,
                    trim((string) $nota->ocompra),
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
            $codigo = strtoupper(trim((string) $nota->ocompra));
            $resultado = $resultados->rellenarFechasOcSiFaltan((int) $nota->nronota, $codigo);

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
                $codigo,
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

    private function handleLimpiarAjenos(NotaMpResultadosService $resultados): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $pendientes = $resultados->contarOcompraFueraDeGrupo();
        $this->info(sprintf('Ocompra con ganador ajeno (no Reicol/Romulo): %d', $pendientes));

        if ($this->option('dry-run') || $pendientes === 0) {
            return self::SUCCESS;
        }

        $limpiadas = $resultados->limpiarOcomprasFueraDeGrupo($limit);
        $this->info(sprintf('Limpiadas: %d (limit=%d)', $limpiadas, $limit));

        return self::SUCCESS;
    }
}

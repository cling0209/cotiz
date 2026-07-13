<?php

namespace App\Console\Commands;

use App\Services\AgileVinculoAuditoriaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AuditarVinculosAgileCommand extends Command
{
    protected $signature = 'cotiz:auditar-vinculos-agile
                            {--score-minimo= : Umbral score (default config cotiz.agile.vinculo_score_minimo)}
                            {--csv= : Ruta CSV de salida (default storage/app/auditoria_vinculos_agile.csv)}
                            {--solo-malas : Solo imprime/exporta las malas}
                            {--aplicar : Elimina de agilemaeprod las marcadas como mala}
                            {--force : No pide confirmación al aplicar}';

    protected $description = 'Audita todas las vinculaciones agilemaeprod (OK/MALA) y opcionalmente limpia las malas';

    public function handle(AgileVinculoAuditoriaService $auditoria): int
    {
        $scoreOpt = $this->option('score-minimo');
        $scoreMinimo = $scoreOpt !== null && $scoreOpt !== ''
            ? (float) $scoreOpt
            : $auditoria->scoreMinimoDefault();

        $this->info(sprintf('Auditando vinculaciones (score mínimo: %.0f)...', $scoreMinimo));

        $resultados = $auditoria->auditarTodos($scoreMinimo);
        $total = $resultados->count();
        $malas = $resultados->where('estado', 'mala');
        $ok = $resultados->where('estado', 'ok');

        $this->table(
            ['Métrica', 'Cantidad'],
            [
                ['Total vinculadas', $total],
                ['OK', $ok->count()],
                ['MALA', $malas->count()],
            ],
        );

        $porMotivo = $malas->groupBy('motivo')->map->count()->sortDesc();
        if ($porMotivo->isNotEmpty()) {
            $this->info('Malas por motivo:');
            foreach ($porMotivo as $motivo => $cant) {
                $this->line(sprintf('  %s: %d', $motivo, $cant));
            }
        }

        $aExportar = $this->option('solo-malas') ? $malas->values() : $resultados;
        $csvPath = $this->resolverCsvPath();
        $this->escribirCsv($csvPath, $aExportar);
        $this->info('CSV: '.$csvPath);

        $muestra = $malas->take(15)->map(fn (array $r) => [
            mb_substr($r['descripcion'], 0, 48),
            $r['prod_item'],
            mb_substr($r['prod_nombre'], 0, 40),
            $r['motivo'],
            $r['score'],
        ])->all();

        if ($muestra !== []) {
            $this->newLine();
            $this->warn('Muestra de malas (máx. 15):');
            $this->table(
                ['Descripción MP', 'Código', 'Maestro', 'Motivo', 'Score'],
                $muestra,
            );
        }

        if (! $this->option('aplicar')) {
            $this->comment('Dry-run: no se borró nada. Usa --aplicar para eliminar las malas.');

            return self::SUCCESS;
        }

        $ids = $auditoria->idsMalos($resultados);
        if ($ids === []) {
            $this->info('No hay filas malas para eliminar.');

            return self::SUCCESS;
        }

        if (! $this->option('force')
            && ! $this->confirm(sprintf('¿Eliminar %d vínculo(s) malo(s) de agilemaeprod?', count($ids)), false)
        ) {
            $this->warn('Cancelado.');

            return self::SUCCESS;
        }

        $borradas = $auditoria->eliminarPorIds($ids);
        $this->info(sprintf('Eliminadas: %d fila(s) de agilemaeprod.', $borradas));

        return self::SUCCESS;
    }

    private function resolverCsvPath(): string
    {
        $opt = trim((string) $this->option('csv'));
        if ($opt !== '') {
            return $opt;
        }

        return storage_path('app/auditoria_vinculos_agile.csv');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $filas
     */
    private function escribirCsv(string $path, $filas): void
    {
        $dir = dirname($path);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('No se pudo crear CSV: '.$path);
        }

        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, [
            'prod_item_agile',
            'descripcion',
            'prod_item',
            'prod_nombre',
            'score',
            'estado',
            'motivo',
        ], ';');

        foreach ($filas as $fila) {
            fputcsv($fh, [
                $fila['prod_item_agile'],
                $fila['descripcion'],
                $fila['prod_item'],
                $fila['prod_nombre'],
                $fila['score'],
                $fila['estado'],
                $fila['motivo'],
            ], ';');
        }

        fclose($fh);
    }
}

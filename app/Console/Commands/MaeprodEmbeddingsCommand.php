<?php

namespace App\Console\Commands;

use App\Models\Maeprod;
use App\Services\MaeprodEmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MaeprodEmbeddingsCommand extends Command
{
    protected $signature = 'cotiz:maeprod-embeddings
                            {--missing : Solo productos sin embedding o con texto desactualizado (default)}
                            {--all : Regenerar todos los embeddings}
                            {--item= : Un solo prod_item}
                            {--limit=0 : Máximo de productos por ejecución (0 = sin tope, en bucle)}
                            {--sleep-ms= : Pausa entre llamadas (default config)}
                            {--queue : Encolar backfill masivo por lotes (un job, no uno por SKU)}';

    protected $description = 'Backfill masivo de embeddings pgvector (Reicol/Romulo: una vez por instancia).';

    public function handle(MaeprodEmbeddingService $embeddings): int
    {
        if (! $embeddings->vectoresHabilitados()) {
            $this->error('Vectores no disponibles: requiere PostgreSQL, extensión vector y migración aplicada.');

            return self::FAILURE;
        }

        if (! app(\App\Services\GeminiClientService::class)->isConfigured()) {
            $this->error('GEMINI_API_KEY no configurada.');

            return self::FAILURE;
        }

        if ($this->option('queue')) {
            $embeddings->programarBackfillMasivo();
            $this->info('Backfill masivo encolado (MaeprodEmbeddingsBackfillJob).');

            return self::SUCCESS;
        }

        $item = trim((string) $this->option('item'));
        $limit = max(0, (int) $this->option('limit'));
        $sleepOpt = $this->option('sleep-ms');
        $sleepMs = $sleepOpt !== null && $sleepOpt !== ''
            ? max(0, min(5000, (int) $sleepOpt))
            : max(0, (int) config('cotiz.busqueda_vectores.backfill_sleep_ms', 150));
        config(['cotiz.busqueda_vectores.backfill_sleep_ms' => $sleepMs]);
        $soloMissing = (bool) $this->option('missing');
        $all = (bool) $this->option('all');
        if (! $soloMissing && ! $all && $item === '') {
            $soloMissing = true;
        }

        $query = Maeprod::query()
            ->whereNotNull('prod_nombre')
            ->where('prod_nombre', '!=', '')
            ->whereNotNull('prod_item')
            ->where('prod_item', '!=', '')
            ->orderBy('prod_item');

        if ($item !== '') {
            $query->where('prod_item', $item);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('No hay productos pendientes de embedding.');

            return self::SUCCESS;
        }

        $this->info('Procesando hasta '.$total.' producto(s)…');
        $ok = 0;
        $fail = 0;
        $n = 0;

        $query->chunk(50, function ($productos) use ($embeddings, $all, $soloMissing, $sleepMs, &$ok, &$fail, &$n) {
            foreach ($productos as $producto) {
                if ($soloMissing && ! $all && ! $embeddings->necesitaActualizar($producto)) {
                    continue;
                }
                $n++;
                if ($embeddings->guardarEmbedding($producto, $all)) {
                    $ok++;
                    $this->line("  [{$n}] OK {$producto->prod_item}");
                } else {
                    $fail++;
                    $this->warn("  [{$n}] FALLÓ {$producto->prod_item}");
                }
                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }
        });

        $conEmbedding = (int) DB::table('maeprod')->whereNotNull('prod_embedding')->count();
        $this->newLine();
        $this->info("Listo: {$ok} OK, {$fail} fallos. Con embedding en BD: {$conEmbedding}.");

        return $fail > 0 && $ok === 0 ? self::FAILURE : self::SUCCESS;
    }
}

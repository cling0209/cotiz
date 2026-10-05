<?php

namespace App\Services;

use App\Jobs\MaeprodEmbeddingsBackfillJob;
use App\Models\Maeprod;
use App\Services\Embeddings\ProductEmbeddingProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Embeddings del catálogo (pgvector) para recall semántico en búsqueda de productos.
 */
class MaeprodEmbeddingService
{
    public function __construct(
        protected ProductEmbeddingProvider $embeddingProvider,
    ) {}

    public function proveedorEmbeddingsDisponible(): bool
    {
        return $this->embeddingProvider->isAvailable();
    }

    public function vectoresHabilitados(): bool
    {
        if (! filter_var(config('cotiz.busqueda_vectores.habilitado', true), FILTER_VALIDATE_BOOL)) {
            return false;
        }
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return false;
        }

        return Schema::hasColumn('maeprod', 'prod_embedding');
    }

    public function catalogoConEmbeddings(): bool
    {
        if (! $this->vectoresHabilitados()) {
            return false;
        }

        return Cache::remember('maeprod:embeddings:count', 300, function () {
            return (int) DB::table('maeprod')->whereNotNull('prod_embedding')->limit(1)->count() > 0;
        });
    }

    /**
     * Tras carga masiva o despliegue: un job que procesa lotes (no un job por SKU).
     */
    public function programarBackfillMasivo(): void
    {
        if (! $this->vectoresHabilitados() || ! $this->proveedorEmbeddingsDisponible()) {
            return;
        }

        MaeprodEmbeddingsBackfillJob::dispatch();
    }

    /**
     * Crear/editar producto en mantenedor: actualiza el vector de ese SKU al instante (sin cola).
     */
    public function actualizarAlGuardarProducto(Maeprod $producto): void
    {
        if (! $this->vectoresHabilitados() || ! $this->proveedorEmbeddingsDisponible()) {
            return;
        }

        try {
            $this->guardarEmbedding($producto->fresh() ?? $producto, true);
        } catch (Throwable $e) {
            Log::warning('MaeprodEmbedding: no se actualizó al guardar producto', [
                'prod_item' => $producto->prod_item,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{ok: int, fail: int, procesados: int, pendientes_estimados: int}
     */
    public function procesarLotePendientes(int $limite): array
    {
        $limite = max(1, $limite);
        $sleepMs = max(0, min(5000, (int) config('cotiz.busqueda_vectores.backfill_sleep_ms', 150)));
        $ok = 0;
        $fail = 0;
        $procesados = 0;

        $modeloEsperado = $this->embeddingProvider->modelId();
        $query = $this->queryCatalogoConNombre()
            ->where(function ($q) use ($modeloEsperado) {
                $q->whereNull('prod_embedding')
                    ->orWhereNull('prod_embedding_fuente')
                    ->orWhereNull('prod_embedding_model')
                    ->orWhere('prod_embedding_model', '!=', $modeloEsperado);
            })
            ->orderBy('prod_item');

        $query->chunk(50, function ($productos) use ($limite, $sleepMs, &$ok, &$fail, &$procesados) {
            foreach ($productos as $producto) {
                if ($procesados >= $limite) {
                    return false;
                }
                if (! $this->necesitaActualizar($producto)) {
                    continue;
                }
                $procesados++;
                if ($this->guardarEmbedding($producto, true)) {
                    $ok++;
                } else {
                    $fail++;
                }
                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }
        });

        $pendientes = (int) Maeprod::query()
            ->whereNotNull('prod_nombre')
            ->where('prod_nombre', '!=', '')
            ->where(function ($q) use ($modeloEsperado) {
                $q->whereNull('prod_embedding')
                    ->orWhereNull('prod_embedding_fuente')
                    ->orWhereNull('prod_embedding_model')
                    ->orWhere('prod_embedding_model', '!=', $modeloEsperado);
            })
            ->count();

        return [
            'ok' => $ok,
            'fail' => $fail,
            'procesados' => $procesados,
            'pendientes_estimados' => $pendientes,
        ];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Maeprod>
     */
    private function queryCatalogoConNombre()
    {
        return Maeprod::query()
            ->whereNotNull('prod_nombre')
            ->where('prod_nombre', '!=', '')
            ->whereNotNull('prod_item')
            ->where('prod_item', '!=', '');
    }

    public function textoParaProducto(Maeprod $producto): string
    {
        $partes = array_filter([
            trim((string) $producto->prod_item),
            trim((string) $producto->prod_nombre),
            trim((string) $producto->prod_familia),
        ], static fn (string $p) => $p !== '');

        return implode(' — ', $partes);
    }

    /**
     * @return list<float>
     */
    public function embedTextoDocumento(string $texto): array
    {
        $texto = trim($texto);
        if ($texto === '') {
            return [];
        }

        return $this->embeddingProvider->embedDocument($texto);
    }

    /**
     * @return list<float>
     */
    public function embedTextoConsulta(string $texto): array
    {
        $texto = trim($texto);
        if ($texto === '') {
            return [];
        }

        return $this->embeddingProvider->embedQuery($texto);
    }

    public function necesitaActualizar(Maeprod $producto): bool
    {
        if (! $this->vectoresHabilitados()) {
            return false;
        }

        $fuente = $this->textoParaProducto($producto);
        if ($fuente === '') {
            return false;
        }

        if ($producto->prod_embedding === null) {
            return true;
        }

        $modeloEsperado = $this->embeddingProvider->modelId();
        $modeloGuardado = trim((string) ($producto->prod_embedding_model ?? ''));
        if ($modeloGuardado === '' || $modeloGuardado !== $modeloEsperado) {
            return true;
        }

        $guardada = trim((string) ($producto->prod_embedding_fuente ?? ''));

        return $guardada !== $fuente;
    }

    public function guardarEmbedding(Maeprod $producto, bool $forzar = false): bool
    {
        if (! $this->vectoresHabilitados()) {
            return false;
        }

        $fuente = $this->textoParaProducto($producto);
        if ($fuente === '') {
            return false;
        }

        if (! $forzar && ! $this->necesitaActualizar($producto)) {
            return true;
        }

        try {
            $vector = $this->embedTextoDocumento($fuente);
        } catch (Throwable $e) {
            Log::warning('MaeprodEmbedding: fallo al generar embedding', [
                'prod_item' => $producto->prod_item,
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        if ($vector === []) {
            return false;
        }

        $literal = $this->vectorLiteral($vector);
        $modelo = $this->embeddingProvider->modelId();
        DB::update(
            'UPDATE maeprod SET prod_embedding = ?::vector, prod_embedding_fuente = ?, prod_embedding_model = ?, prod_embedding_at = ? WHERE prod_item = ?',
            [$literal, $fuente, $modelo, now(), $producto->prod_item],
        );
        Cache::forget('maeprod:embeddings:count');

        return true;
    }

    /**
     * @return Collection<int, Maeprod>
     */
    public function buscarSimilares(string $consulta, ?string $familia, int $limit): Collection
    {
        if (! $this->vectoresHabilitados() || ! $this->catalogoConEmbeddings()) {
            return collect();
        }

        $consulta = trim($consulta);
        if ($consulta === '') {
            return collect();
        }

        $limit = max(1, min(50, $limit));
        $topK = max($limit, (int) config('cotiz.busqueda_vectores.top_k', 30));
        $maxDistancia = (float) config('cotiz.busqueda_vectores.max_distancia_coseno', 0.42);

        try {
            $vector = $this->embedConsulta($consulta);
        } catch (Throwable $e) {
            Log::warning('MaeprodEmbedding: fallo embedding de consulta', ['message' => $e->getMessage()]);

            return collect();
        }

        if ($vector === []) {
            return collect();
        }

        $literal = $this->vectorLiteral($vector);
        $bindings = [$literal, $literal, $maxDistancia];
        $sql = 'SELECT prod_item, prod_nombre, prod_valor, prod_valor_costo, prod_stock_real, prod_familia, prod_imagen, '
            .'(prod_embedding <=> ?::vector) AS distancia '
            .'FROM maeprod '
            .'WHERE prod_embedding IS NOT NULL '
            .'AND prod_nombre IS NOT NULL AND prod_nombre <> \'\' '
            .'AND prod_item IS NOT NULL AND prod_item <> \'\' '
            .'AND (prod_embedding <=> ?::vector) <= ? ';

        if ($familia !== null && trim($familia) !== '') {
            $sql .= 'AND prod_familia = ? ';
            $bindings[] = trim($familia);
        }

        $sql .= 'ORDER BY distancia ASC LIMIT ?';
        $bindings[] = $topK;

        $rows = DB::select($sql, $bindings);

        $out = [];
        foreach ($rows as $row) {
            $item = trim((string) $row->prod_item);
            if ($item === '' || isset($out[$item])) {
                continue;
            }
            $out[$item] = Maeprod::query()->find($item) ?? new Maeprod([
                'prod_item' => $row->prod_item,
                'prod_nombre' => $row->prod_nombre,
                'prod_valor' => $row->prod_valor,
                'prod_valor_costo' => $row->prod_valor_costo,
                'prod_stock_real' => $row->prod_stock_real,
                'prod_familia' => $row->prod_familia,
                'prod_imagen' => $row->prod_imagen,
            ]);
            if (count($out) >= $limit) {
                break;
            }
        }

        return collect(array_values($out));
    }

    /**
     * @return list<float>
     */
    private function embedConsulta(string $consulta): array
    {
        $clave = 'maeprod:embed:q:'.md5(mb_strtolower($consulta, 'UTF-8'));
        $ttl = max(60, (int) config('cotiz.busqueda_vectores.cache_consulta_seg', 3600));

        /** @var list<float>|null $cached */
        $cached = Cache::get($clave);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $vector = $this->embedTextoConsulta($consulta);
        if ($vector !== []) {
            Cache::put($clave, $vector, $ttl);
        }

        return $vector;
    }

    /**
     * @param  list<float>  $vector
     */
    public function vectorLiteral(array $vector): string
    {
        $expected = max(64, min(3072, (int) config('cotiz.busqueda_vectores.dimension', 768)));
        if (count($vector) !== $expected) {
            throw new RuntimeException('Dimensión de embedding inesperada: '.count($vector).' (esperado '.$expected.')');
        }

        $parts = array_map(static fn (float $v) => rtrim(rtrim(sprintf('%.8F', $v), '0'), '.'), $vector);

        return '['.implode(',', $parts).']';
    }
}

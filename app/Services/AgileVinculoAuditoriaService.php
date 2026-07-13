<?php

namespace App\Services;

use App\Models\AgileMaeprod;
use App\Models\Maeprod;
use Illuminate\Support\Collection;

/**
 * Audita vínculos aprendizaje agilemaeprod (descripción → prod_item)
 * con la misma lógica de solape/score usada al importar.
 */
class AgileVinculoAuditoriaService
{
    public const MOTIVO_OK = 'ok';

    public const MOTIVO_SIN_DESCRIPCION = 'sin_descripcion';

    public const MOTIVO_MAESTRO_INEXISTENTE = 'maestro_inexistente';

    public const MOTIVO_SIN_SOLAPE = 'sin_solape';

    public const MOTIVO_SCORE_BAJO = 'score_bajo';

    public function __construct(
        protected MaeprodBusquedaSimilitudService $busqueda,
    ) {}

    public function scoreMinimoDefault(): float
    {
        return (float) config(
            'cotiz.agile.vinculo_score_minimo',
            config('cotiz.buscar_productos_score_php_minimo', 5000),
        );
    }

    /**
     * @return Collection<int, array{
     *   prod_item_agile: string,
     *   descripcion: string,
     *   prod_item: string,
     *   prod_nombre: string,
     *   score: float,
     *   estado: 'ok'|'mala',
     *   motivo: string
     * }>
     */
    public function auditarTodos(?float $scoreMinimo = null): Collection
    {
        $minimo = $scoreMinimo ?? $this->scoreMinimoDefault();

        $filas = AgileMaeprod::query()
            ->whereNotNull('prod_item')
            ->where('prod_item', '!=', '')
            ->where('prod_item', '!=', '0')
            ->orderBy('prod_item_agile')
            ->get();

        $maestros = Maeprod::query()
            ->whereIn('prod_item', $filas->pluck('prod_item')->unique()->filter()->values()->all())
            ->get()
            ->keyBy('prod_item');

        return $filas->map(function (AgileMaeprod $row) use ($maestros, $minimo) {
            return $this->evaluarFila($row, $maestros->get((string) $row->prod_item), $minimo);
        })->values();
    }

    /**
     * @return array{
     *   prod_item_agile: string,
     *   descripcion: string,
     *   prod_item: string,
     *   prod_nombre: string,
     *   score: float,
     *   estado: 'ok'|'mala',
     *   motivo: string
     * }
     */
    public function evaluarFila(AgileMaeprod $row, ?Maeprod $maestro, float $scoreMinimo): array
    {
        $descripcion = trim((string) ($row->prod_descripcion_agile ?? ''));
        $prodItem = trim((string) ($row->prod_item ?? ''));
        $prodNombre = $maestro ? trim((string) ($maestro->prod_nombre ?? '')) : '';

        $base = [
            'prod_item_agile' => (string) $row->prod_item_agile,
            'descripcion' => $descripcion,
            'prod_item' => $prodItem,
            'prod_nombre' => $prodNombre,
            'score' => 0.0,
            'estado' => 'mala',
            'motivo' => self::MOTIVO_OK,
        ];

        if ($descripcion === '') {
            $base['motivo'] = self::MOTIVO_SIN_DESCRIPCION;

            return $base;
        }

        if ($maestro === null || $prodNombre === '') {
            $base['motivo'] = self::MOTIVO_MAESTRO_INEXISTENTE;

            return $base;
        }

        $score = $this->busqueda->scoreSimilitudFila($descripcion, $prodItem, $prodNombre);
        $base['score'] = round($score, 2);

        if (! $this->busqueda->tieneSolapeDistintivo($descripcion, $prodNombre)) {
            $base['motivo'] = self::MOTIVO_SIN_SOLAPE;

            return $base;
        }

        if ($score < $scoreMinimo) {
            $base['motivo'] = self::MOTIVO_SCORE_BAJO;

            return $base;
        }

        $base['estado'] = 'ok';
        $base['motivo'] = self::MOTIVO_OK;

        return $base;
    }

    /**
     * @param  Collection<int, array{prod_item_agile: string, estado: string}>  $auditoria
     * @return list<string>
     */
    public function idsMalos(Collection $auditoria): array
    {
        return $auditoria
            ->filter(fn (array $r) => ($r['estado'] ?? '') === 'mala')
            ->map(fn (array $r) => (string) $r['prod_item_agile'])
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $prodItemAgileIds
     */
    public function eliminarPorIds(array $prodItemAgileIds): int
    {
        if ($prodItemAgileIds === []) {
            return 0;
        }

        return AgileMaeprod::query()
            ->whereIn('prod_item_agile', $prodItemAgileIds)
            ->delete();
    }
}

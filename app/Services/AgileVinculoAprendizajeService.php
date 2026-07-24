<?php

namespace App\Services;

use App\Enums\VinculoOrigen;
use App\Models\AgileMaeprod;
use App\Models\Maeprod;
use App\Models\MaeprodFrase;
use App\Support\AgileDescripcion;

/**
 * Vinculación Agile → maeprod por descripción aprendida (no por codigo_producto MP).
 */
class AgileVinculoAprendizajeService
{
    public function __construct(
        protected MaeprodBusquedaSimilitudService $busqueda,
    ) {}

    public function descripcionNormalizada(string $descripcion): string
    {
        return $this->busqueda->normalizarTexto(AgileDescripcion::normalizar($descripcion));
    }

    public function hashDescripcion(string $descripcion): string
    {
        $norm = $this->descripcionNormalizada($descripcion);

        return $norm === '' ? '' : md5($norm);
    }

    /**
     * @return ?array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}
     */
    public function buscarCodigoAprendido(string $descripcion): ?array
    {
        $resultado = $this->resolverVinculoAprendido($descripcion);

        return $resultado['producto'] ?? null;
    }

    /**
     * @return array{
     *   producto: ?array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int},
     *   estado: string,
     *   es_sugerencia: bool,
     *   origen: ?string
     * }
     */
    public function resolverParaImportacion(string $descripcion): array
    {
        $descripcion = trim($descripcion);
        if ($descripcion === '') {
            return $this->sinMatch();
        }

        $aprendido = $this->resolverVinculoAprendido($descripcion);
        if ($aprendido['producto'] !== null) {
            return [
                'producto' => $aprendido['producto'],
                'estado' => 'vinculado',
                'es_sugerencia' => false,
                'origen' => $aprendido['origen'],
            ];
        }

        $sugerencia = $this->resolverSugerenciaMaeprod($descripcion);
        if ($sugerencia !== null) {
            return [
                'producto' => $sugerencia,
                'estado' => 'pendiente',
                'es_sugerencia' => true,
                'origen' => 'maeprod_similitud',
            ];
        }

        return $this->sinMatch();
    }

    public function guardarAprendizaje(
        string $descripcion,
        string $prodItem,
        ?string $codigoCategoriaMp = null,
        ?string $referenciaAgile = null,
        ?string $usuario = null,
        VinculoOrigen $origen = VinculoOrigen::SISTEMA,
        ?int $nronota = null,
    ): void {
        $descripcion = trim($descripcion);
        $prodItem = trim($prodItem);
        if ($descripcion === '' || $prodItem === '') {
            return;
        }

        if (! Maeprod::query()->where('prod_item', $prodItem)->exists()) {
            return;
        }

        $hash = $this->hashDescripcion($descripcion);
        if ($hash === '') {
            return;
        }

        $descGuardada = AgileDescripcion::paraMaeprod($descripcion);
        $claveAgile = 'desc:'.substr($hash, 0, 43);
        $codigoMp = $codigoCategoriaMp ? trim($codigoCategoriaMp) : trim((string) $referenciaAgile);
        $usuario = $usuario !== null ? mb_substr(trim($usuario), 0, 100) : null;

        $auditoria = [
            'vinculado_por' => $usuario !== '' ? $usuario : null,
            'vinculado_en' => now(),
            'vinculado_origen' => $origen->value,
            'vinculado_nota' => ($nronota !== null && $nronota > 0) ? $nronota : null,
        ];

        $existente = AgileMaeprod::query()->where('descripcion_norm_hash', $hash)->first();

        if ($existente) {
            $existente->update(array_merge([
                'prod_item' => $prodItem,
                'prod_descripcion_agile' => $descGuardada,
                'prod_codigo_categoria_mp' => $codigoMp !== ''
                    ? mb_substr($codigoMp, 0, 50)
                    : $existente->prod_codigo_categoria_mp,
            ], $auditoria));

            return;
        }

        AgileMaeprod::query()->create(array_merge([
            'prod_item_agile' => mb_substr($claveAgile, 0, 50),
            'prod_descripcion_agile' => $descGuardada,
            'prod_item' => $prodItem,
            'descripcion_norm_hash' => $hash,
            'prod_codigo_categoria_mp' => $codigoMp !== '' ? mb_substr($codigoMp, 0, 50) : null,
        ], $auditoria));
    }

    /**
     * @return array{producto: ?array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}, origen: ?string}
     */
    private function resolverVinculoAprendido(string $descripcion): array
    {
        $porFrase = $this->resolverPorFraseMaestro($descripcion);
        if ($porFrase !== null) {
            return [
                'producto' => $porFrase,
                'origen' => 'frase_maeprod',
            ];
        }

        $hash = $this->hashDescripcion($descripcion);
        if ($hash !== '') {
            $exacto = AgileMaeprod::query()
                ->where('descripcion_norm_hash', $hash)
                ->whereNotNull('prod_item')
                ->where('prod_item', '!=', '')
                ->where('prod_item', '!=', '0')
                ->first();

            if ($exacto) {
                $mae = Maeprod::query()->find($exacto->prod_item);
                if ($mae) {
                    return [
                        'producto' => $this->maeprodArray($mae),
                        'origen' => 'aprendido_exacto',
                    ];
                }
            }
        }

        $simil = $this->buscarSimilitudEnAprendizaje($descripcion);
        if ($simil !== null) {
            return [
                'producto' => $simil,
                'origen' => 'aprendido_similitud',
            ];
        }

        return ['producto' => null, 'origen' => null];
    }

    /**
     * Match por “contiene”: la frase del maestro está dentro de la descripción Agile.
     * Si varias coinciden, gana la frase más larga (más específica).
     *
     * @return ?array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}
     */
    private function resolverPorFraseMaestro(string $descripcion): ?array
    {
        $descNorm = $this->descripcionNormalizada($descripcion);
        if ($descNorm === '') {
            return null;
        }

        $candidatas = MaeprodFrase::query()
            ->where('frase_norm', '!=', '')
            ->orderByRaw('LENGTH(frase_norm) DESC')
            ->orderBy('id')
            ->get(['prod_item', 'frase_norm']);

        if ($candidatas->isEmpty()) {
            return null;
        }

        foreach ($candidatas as $frase) {
            $norm = (string) $frase->frase_norm;
            if ($norm === '') {
                continue;
            }

            if (str_contains($descNorm, $norm)) {
                $mae = Maeprod::query()->find($frase->prod_item);
                if ($mae) {
                    return $this->maeprodArray($mae);
                }
            }
        }

        return null;
    }

    /**
     * @return ?array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}
     */
    private function buscarSimilitudEnAprendizaje(string $descripcion): ?array
    {
        $norm = $this->descripcionNormalizada($descripcion);
        if ($norm === '') {
            return null;
        }

        $tokensDist = $this->busqueda->tokensDistintivos($norm);
        $tokens = $tokensDist !== []
            ? $tokensDist
            : $this->busqueda->tokensSignificativos($norm);
        if ($tokens === []) {
            return null;
        }

        $query = AgileMaeprod::query()
            ->whereNotNull('prod_descripcion_agile')
            ->where('prod_descripcion_agile', '!=', '')
            ->whereNotNull('prod_item')
            ->where('prod_item', '!=', '')
            ->where('prod_item', '!=', '0');

        $query->where(function ($q) use ($tokens) {
            $like = $q->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            foreach (array_slice($tokens, 0, 6) as $token) {
                $q->orWhere('prod_descripcion_agile', $like, '%'.$token.'%');
            }
        });

        $candidatos = $query->limit(80)->get();
        if ($candidatos->isEmpty()) {
            return null;
        }

        $mejorScore = 0.0;
        $mejorProdItem = null;

        foreach ($candidatos as $row) {
            $descAprendida = (string) ($row->prod_descripcion_agile ?? '');
            if (! $this->busqueda->tieneSolapeDistintivo($descripcion, $descAprendida)) {
                continue;
            }

            $score = $this->busqueda->scoreSimilitudFila(
                $descripcion,
                (string) $row->prod_item,
                $descAprendida,
            );

            if ($score > $mejorScore) {
                $mejorScore = $score;
                $mejorProdItem = (string) $row->prod_item;
            }
        }

        $minimo = $this->scoreMinimoAprendido();
        if ($mejorProdItem === null || $mejorScore < $minimo) {
            return null;
        }

        $mae = Maeprod::query()->find($mejorProdItem);
        if (! $mae) {
            return null;
        }

        // Doble chequeo contra el nombre real del maestro (no solo la descripción aprendida).
        if (! $this->busqueda->tieneSolapeDistintivo($descripcion, (string) $mae->prod_nombre)) {
            return null;
        }

        return $this->maeprodArray($mae);
    }

    /**
     * @return ?array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}
     */
    private function resolverSugerenciaMaeprod(string $descripcion): ?array
    {
        $resultados = $this->busqueda->buscar($descripcion, null, 1);
        $mae = $resultados->first();
        if (! $mae instanceof Maeprod) {
            return null;
        }

        if (! $this->busqueda->tieneSolapeDistintivo($descripcion, (string) $mae->prod_nombre)) {
            return null;
        }

        return $this->maeprodArray($mae);
    }

    /**
     * @return array{producto: null, estado: string, es_sugerencia: bool, origen: null}
     */
    private function sinMatch(): array
    {
        return [
            'producto' => null,
            'estado' => 'pendiente',
            'es_sugerencia' => false,
            'origen' => null,
        ];
    }

    private function scoreMinimoAprendido(): float
    {
        return (float) config('cotiz.agile.vinculo_score_minimo', config('cotiz.buscar_productos_score_php_minimo', 5000));
    }

    /**
     * @return array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}
     */
    private function maeprodArray(Maeprod $mae): array
    {
        return [
            'prod_item' => (string) $mae->prod_item,
            'prod_nombre' => (string) $mae->prod_nombre,
            'prod_valor' => (int) ($mae->prod_valor ?? 0),
            'prod_valor_costo' => (int) ($mae->prod_valor_costo ?? 0),
        ];
    }
};

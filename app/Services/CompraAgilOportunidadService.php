<?php

namespace App\Services;

use App\Models\CompraAgilProceso;
use App\Models\Nota;
use App\Models\OportunidadEncontrada;
use RuntimeException;

class CompraAgilOportunidadService
{
    public function __construct(
        protected CompraAgilApiService $api,
        protected CompraAgilPayloadMapper $mapper,
        protected MaeprodBusquedaSimilitudService $busqueda,
    ) {}

    /**
     * Valida existencia para cargar: primero base local; si no está, consulta MP.
     * Si MP no tiene el código, lanza el mensaje de carga bloqueada.
     *
     * @return array{origen: 'local'|'mp', codigo: string, payload: array<string, mixed>|null}
     */
    public function asegurarCodigoExisteEnMp(string $codigo): array
    {
        $codigo = strtoupper(trim($codigo));
        if ($codigo === '') {
            throw new RuntimeException('Indique el número de cotización Compra Ágil.');
        }

        if ($this->existeEnBaseLocal($codigo)) {
            return [
                'origen' => 'local',
                'codigo' => $codigo,
                'payload' => null,
            ];
        }

        try {
            $payload = $this->api->detalle($codigo);
        } catch (RuntimeException $e) {
            if (CompraAgilApiService::esNoExisteEnMp($e->getMessage())) {
                throw new RuntimeException(CompraAgilApiService::mensajeNoExisteEnMp($codigo), 0, $e);
            }

            throw $e;
        }

        return [
            'origen' => 'mp',
            'codigo' => $codigo,
            'payload' => $payload,
        ];
    }

    /**
     * Detalle MP para preview/importación. Normaliza el mensaje si el código no existe.
     *
     * @return array<string, mixed>
     */
    public function detalleParaCarga(string $codigo): array
    {
        $codigo = strtoupper(trim($codigo));
        if ($codigo === '') {
            throw new RuntimeException('Indique el número de cotización Compra Ágil.');
        }

        try {
            $payload = $this->api->detalle($codigo);
        } catch (RuntimeException $e) {
            if (CompraAgilApiService::esNoExisteEnMp($e->getMessage())) {
                throw new RuntimeException(CompraAgilApiService::mensajeNoExisteEnMp($codigo), 0, $e);
            }

            throw $e;
        }

        if (CompraAgilRegionScope::debeExcluirItem($payload)) {
            throw new RuntimeException(CompraAgilRegionScope::mensajeZonaExcluida());
        }

        return $payload;
    }

    public function existeEnBaseLocal(string $codigo): bool
    {
        $codigo = strtoupper(trim($codigo));
        if ($codigo === '') {
            return false;
        }

        return OportunidadEncontrada::query()->where('codigo', $codigo)->exists()
            || CompraAgilProceso::query()->whereKey($codigo)->exists()
            || Nota::query()->whereRaw('upper(trim(encargado)) = ?', [$codigo])->exists();
    }

    public function esCodigoCompraAgil(string $codigo): bool
    {
        $codigo = strtoupper(trim($codigo));

        return $codigo !== '' && (bool) preg_match('/^\d+-\d+-COT\d+$/', $codigo);
    }

    /**
     * Al grabar: exige que el código exista en Oportunidades (local) o en MP.
     * Si no existe en ninguno, lanza RuntimeException.
     */
    public function assertExisteEnMpSiCompraAgil(string $codigo): void
    {
        $codigo = strtoupper(trim($codigo));
        if ($codigo === '') {
            return;
        }

        if ($this->existeEnBaseLocal($codigo)) {
            return;
        }

        if ($this->esCodigoCompraAgil($codigo) && $this->api->isConfigured()) {
            $this->detalleParaCarga($codigo);

            return;
        }

        throw new RuntimeException(
            sprintf('La cotización «%s» no existe en Oportunidades. No se puede grabar.', $codigo),
        );
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, paginacion: array<string, mixed>}
     */
    public function listarPublicadas(array $filtros = []): array
    {
        $pageSize = min(50, max(1, (int) ($filtros['tamano_pagina'] ?? 15)));
        $params = [
            'estado' => 'publicada',
            'tamano_pagina' => $pageSize,
            'numero_pagina' => max(1, (int) ($filtros['numero_pagina'] ?? 1)),
            'ordenar_por' => 'FechaPublicacion',
        ];

        $textoBusqueda = trim((string) ($filtros['q'] ?? ''));
        if ($textoBusqueda !== '') {
            $params['q'] = $textoBusqueda;
        }

        $regionFiltro = isset($filtros['region']) ? (int) $filtros['region'] : null;

        if ($textoBusqueda !== '') {
            if ($regionFiltro === null || $regionFiltro <= 0) {
                throw new \RuntimeException('La búsqueda por texto requiere seleccionar una región.');
            }
            if (! in_array($regionFiltro, CompraAgilRegionScope::regionesIncluidas(), true)) {
                throw new \RuntimeException('La región seleccionada no está habilitada para consultas Compra Ágil.');
            }
            $resultado = $this->api->listarEnRegiones($params, [$regionFiltro]);
        } elseif ($regionFiltro !== null && $regionFiltro > 0) {
            $regiones = in_array($regionFiltro, CompraAgilRegionScope::regionesIncluidas(), true)
                ? [$regionFiltro]
                : [];
            $resultado = $this->api->listarEnRegiones($params, $regiones);
        } else {
            $resultado = $this->api->listarEnRegiones($params);
        }

        $items = array_map(fn (array $item) => $this->enriquecerItemListado($item), $resultado['items']);
        $items = array_values(array_filter(
            $items,
            fn (array $item) => ! CompraAgilRegionScope::debeExcluirResumen($item),
        ));

        $items = array_map(function (array $item) {
            if ($item['cantidad_productos'] !== null) {
                return $item;
            }
            $cache = CompraAgilProceso::query()->find($item['codigo'] ?? '');
            if ($cache) {
                $item['cantidad_productos'] = (int) $cache->cantidad_productos;
            }

            return $item;
        }, $items);

        if (($filtros['modo'] ?? '') === 'oportunidad') {
            usort($items, fn ($a, $b) => ($b['score_oportunidad'] ?? 0) <=> ($a['score_oportunidad'] ?? 0));
        }

        $paginacion = $resultado['paginacion'];
        $paginacion['total_resultados'] = count($items);

        return [
            'items' => array_slice($items, 0, $pageSize),
            'paginacion' => $paginacion,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detalleResumen(string $codigo): array
    {
        $payload = $this->detalleParaCarga($codigo);

        $item = $this->mapper->resumenListadoItem(array_merge($payload, [
            'montos' => [
                'monto_disponible_clp' => data_get($payload, 'presupuesto.monto_disponible_clp'),
                'moneda' => data_get($payload, 'presupuesto.moneda', 'CLP'),
            ],
            'fechas' => $payload['fechas'] ?? [],
            'estado' => $payload['estado'] ?? [],
            'resumen' => $payload['resumen'] ?? [],
        ]));
        $item['cantidad_productos'] = $this->mapper->cantidadProductosDetalle($payload);

        return $item;
    }

    /**
     * @param  array<string, mixed>  $resumen
     * @return array<string, mixed>
     */
    public function enriquecerResumen(array $resumen): array
    {
        $resumen['score_oportunidad'] = $this->calcularScore($resumen);
        $resumen['motivo_score'] = $this->motivoScore($resumen);

        return $resumen;
    }

    /**
     * Enriquecimiento sin consultas a BD (para match masivo / listados rápidos).
     *
     * @param  array<string, mixed>  $resumen
     * @return array<string, mixed>
     */
    public function enriquecerResumenBasico(array $resumen): array
    {
        if (! array_key_exists('score_oportunidad', $resumen) || $resumen['score_oportunidad'] === null) {
            $resumen['score_oportunidad'] = 0;
        }
        if (! array_key_exists('motivo_score', $resumen) || $resumen['motivo_score'] === null || $resumen['motivo_score'] === '') {
            $resumen['motivo_score'] = 'Compra pública abierta';
        }

        return $resumen;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function enriquecerItemListado(array $item): array
    {
        return $this->enriquecerResumen($this->mapper->resumenListadoItem($item));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function calcularScore(array $item): int
    {
        $score = 0;
        $rutOrg = trim((string) ($item['rut_organismo'] ?? ''));

        if ($rutOrg !== '') {
            $ganadas = Nota::query()
                ->whereRaw("lower(coalesce(estado, '')) = 'aceptada'")
                ->where('rutempresa', 'ilike', '%'.$rutOrg.'%')
                ->count();
            if ($ganadas > 0) {
                $score += min(40, 15 + ($ganadas * 5));
            }
        }

        $codigo = trim((string) ($item['codigo'] ?? ''));
        if ($codigo !== '') {
            $aceptadaMismoCodigo = Nota::query()
                ->whereRaw("lower(coalesce(estado, '')) = 'aceptada'")
                ->whereRaw('trim(encargado) ilike ?', [$codigo])
                ->exists();
            if ($aceptadaMismoCodigo) {
                $score += 25;
            }
        }

        $nombre = trim((string) ($item['nombre'] ?? ''));
        if ($nombre !== '') {
            $match = $this->busqueda->buscar($nombre, null, 1)->first();
            if ($match !== null) {
                $score += 20;
            }
        }

        $monto = (int) ($item['monto_presupuesto_clp'] ?? 0);
        if ($monto >= 500_000) {
            $score += 10;
        } elseif ($monto >= 100_000) {
            $score += 5;
        }

        return min(100, $score);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function motivoScore(array $item): string
    {
        $partes = [];
        $rutOrg = trim((string) ($item['rut_organismo'] ?? ''));

        if ($rutOrg !== '') {
            $ganadas = Nota::query()
                ->whereRaw("lower(coalesce(estado, '')) = 'aceptada'")
                ->where('rutempresa', 'ilike', '%'.$rutOrg.'%')
                ->count();
            if ($ganadas > 0) {
                $partes[] = 'Cliente con '.$ganadas.' adjudicación(es)';
            }
        }

        $nombre = trim((string) ($item['nombre'] ?? ''));
        if ($nombre !== '' && $this->busqueda->buscar($nombre, null, 1)->isNotEmpty()) {
            $partes[] = 'Rubro alineado al catálogo';
        }

        if ($partes === []) {
            return 'Compra pública abierta';
        }

        return implode(' · ', $partes);
    }

    /**
     * @return array<string, int>
     */
    public function estadisticasOrganismo(string $rut): array
    {
        $rut = preg_replace('/\s+/u', '', trim($rut)) ?? '';

        return [
            'adjudicadas' => Nota::query()
                ->whereRaw("lower(coalesce(estado, '')) = 'aceptada'")
                ->where('rutempresa', 'ilike', '%'.$rut.'%')
                ->count(),
            'cotizaciones' => Nota::query()
                ->where('rutempresa', 'ilike', '%'.$rut.'%')
                ->whereRaw("trim(coalesce(encargado, '')) <> ''")
                ->count(),
        ];
    }
}

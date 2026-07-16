<?php

namespace App\Services;

use App\Models\OportunidadEncontrada;
use App\Models\OportunidadPalabraClave;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class OportunidadParaCotizarService
{
    private const CACHE_SEGUNDOS = 60;

    public function __construct(
        protected CompraAgilApiService $api,
        protected CompraAgilOportunidadService $oportunidad,
        protected CompraAgilPayloadMapper $mapper,
        protected OportunidadEncontradaRelayService $encontradaRelay,
    ) {}

    /**
     * @return list<string>
     */
    public function palabrasClave(): array
    {
        return OportunidadPalabraClave::query()
            ->orderBy('orden')
            ->orderBy('id')
            ->pluck('frase')
            ->map(fn ($f) => trim((string) $f))
            ->filter(fn ($f) => $f !== '')
            ->values()
            ->all();
    }

    public function fechaBusquedaHoy(): string
    {
        return now()->timezone(config('app.timezone'))->toDateString();
    }

    /**
     * Oportunidades ya grabadas hoy (para mostrar al abrir la pantalla).
     *
     * @return list<array<string, mixed>>
     */
    public function listarGuardadasHoy(): array
    {
        $items = OportunidadEncontrada::query()
            ->whereDate('fecha_busqueda', $this->fechaBusquedaHoy())
            ->orderBy('indice_region_config')
            ->orderByDesc('monto_presupuesto_clp')
            ->orderBy('codigo')
            ->get()
            ->map(fn (OportunidadEncontrada $row) => $row->toResumen())
            ->all();

        usort($items, [$this, 'compararOportunidades']);

        return $items;
    }

    /**
     * @return list<string>
     */
    public function codigosGuardadosHoy(): array
    {
        return OportunidadEncontrada::query()
            ->whereDate('fecha_busqueda', $this->fechaBusquedaHoy())
            ->pluck('codigo')
            ->map(fn ($c) => strtoupper(trim((string) $c)))
            ->filter(fn ($c) => $c !== '')
            ->values()
            ->all();
    }

    /**
     * Persiste oportunidades encontradas (upsert por código + día).
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function guardarEncontradas(array $items, ?int $userId = null): int
    {
        $dia = $this->fechaBusquedaHoy();
        $guardadas = 0;
        $paraSync = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $codigo = strtoupper(trim((string) ($item['codigo'] ?? '')));
            if ($codigo === '') {
                continue;
            }

            $palabras = array_values(array_unique(array_filter(array_map(
                static fn ($p) => trim((string) $p),
                is_array($item['palabras_coinciden'] ?? null) ? $item['palabras_coinciden'] : [],
            ))));

            $existente = OportunidadEncontrada::query()
                ->where('codigo', $codigo)
                ->whereDate('fecha_busqueda', $dia)
                ->first();

            if ($existente !== null) {
                $prev = is_array($existente->palabras_coinciden) ? $existente->palabras_coinciden : [];
                $merged = array_values(array_unique(array_merge($prev, $palabras)));
                $existente->fill($this->atributosDesdeResumen($item, $dia, $userId, $merged));
                $existente->save();
                $guardadas++;
                $paraSync[] = $existente->toResumen() + ['fecha_busqueda' => $dia];

                continue;
            }

            $creada = OportunidadEncontrada::query()->create(
                $this->atributosDesdeResumen($item, $dia, $userId, $palabras),
            );
            $guardadas++;
            $paraSync[] = $creada->toResumen() + ['fecha_busqueda' => $dia];
        }

        if ($paraSync !== []) {
            $this->encontradaRelay->replicarItems($paraSync);
        }

        return $guardadas;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $palabras
     * @return array<string, mixed>
     */
    private function atributosDesdeResumen(array $item, string $dia, ?int $userId, array $palabras): array
    {
        $region = isset($item['region']) ? (int) $item['region'] : null;

        return [
            'codigo' => strtoupper(trim((string) ($item['codigo'] ?? ''))),
            'nombre' => mb_substr(trim((string) ($item['nombre'] ?? '')), 0, 500) ?: null,
            'organismo' => mb_substr(trim((string) ($item['organismo'] ?? '')), 0, 500) ?: null,
            'rut_organismo' => mb_substr(trim((string) ($item['rut_organismo'] ?? '')), 0, 20) ?: null,
            'region' => $region,
            'nombre_region' => mb_substr(trim((string) ($item['nombre_region'] ?? '')), 0, 100) ?: null,
            'comuna' => mb_substr(trim((string) ($item['comuna'] ?? '')), 0, 120) ?: null,
            'monto_presupuesto_clp' => isset($item['monto_presupuesto_clp'])
                ? (int) $item['monto_presupuesto_clp']
                : null,
            'moneda' => mb_substr(trim((string) ($item['moneda'] ?? 'CLP')), 0, 10) ?: 'CLP',
            'fecha_publicacion' => $this->parseFechaNullable($item['fecha_publicacion'] ?? null),
            'fecha_cierre' => $this->parseFechaNullable($item['fecha_cierre'] ?? null),
            'estado_codigo' => mb_substr(trim((string) ($item['estado_codigo'] ?? '')), 0, 40) ?: null,
            'estado_glosa' => mb_substr(trim((string) ($item['estado_glosa'] ?? '')), 0, 120) ?: null,
            'palabras_coinciden' => $palabras,
            'cantidad_productos' => isset($item['cantidad_productos'])
                ? (int) $item['cantidad_productos']
                : null,
            'fecha_busqueda' => $dia,
            'indice_region_config' => (int) ($item['indice_region_config']
                ?? CompraAgilRegionScope::indiceEnConfig($region)),
            'found_by' => $userId,
        ];
    }

    private function parseFechaNullable(mixed $valor): ?Carbon
    {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return null;
        }

        try {
            return Carbon::parse($valor);
        } catch (\Throwable) {
            return null;
        }
    }

    private function agregarPalabraAGuardada(string $codigo, string $frase): void
    {
        $frase = trim($frase);
        if ($codigo === '' || $frase === '') {
            return;
        }

        $row = OportunidadEncontrada::query()
            ->where('codigo', $codigo)
            ->whereDate('fecha_busqueda', $this->fechaBusquedaHoy())
            ->first();

        if ($row === null) {
            return;
        }

        if (! $this->fraseApareceEnTexto($frase, $row->toResumen())) {
            return;
        }

        $prev = is_array($row->palabras_coinciden) ? $row->palabras_coinciden : [];
        if (in_array($frase, $prev, true)) {
            return;
        }

        $prev[] = $frase;
        $row->palabras_coinciden = array_values($prev);
        $row->save();

        $dia = $this->fechaBusquedaHoy();
        $this->encontradaRelay->replicarItems([
            $row->toResumen() + ['fecha_busqueda' => $dia],
        ]);
    }

    private function completarCantidadProductosGuardada(string $codigo): ?int
    {
        $row = OportunidadEncontrada::query()
            ->where('codigo', $codigo)
            ->whereDate('fecha_busqueda', $this->fechaBusquedaHoy())
            ->first();

        if ($row === null) {
            return null;
        }

        if ($row->cantidad_productos !== null) {
            return (int) $row->cantidad_productos;
        }

        $cantidad = $this->obtenerCantidadProductosReal($codigo);
        if ($cantidad !== null) {
            $row->cantidad_productos = $cantidad;
            $row->save();
        }

        return $cantidad;
    }

    private function obtenerCantidadProductosReal(string $codigo): ?int
    {
        try {
            // detalle() usa cache: como máximo una consulta real por código durante su TTL.
            $detalle = $this->api->detalle($codigo);

            return $this->mapper->cantidadProductosDetalle($detalle);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * La frase (o todas sus palabras significativas) debe aparecer en nombre/organismo.
     *
     * @param  array<string, mixed>  $resumen
     * @param  array<string, mixed>|null  $crudo  ítem crudo API (opcional)
     */
    public function fraseApareceEnTexto(string $frase, array $resumen, ?array $crudo = null): bool
    {
        $frase = trim($frase);
        if ($frase === '') {
            return false;
        }

        $partes = [
            (string) ($resumen['nombre'] ?? ''),
            (string) ($resumen['organismo'] ?? ''),
            (string) ($resumen['comuna'] ?? ''),
            (string) ($resumen['nombre_region'] ?? ''),
        ];

        if (is_array($crudo)) {
            $partes[] = (string) ($crudo['nombre'] ?? '');
            $institucion = is_array($crudo['institucion'] ?? null) ? $crudo['institucion'] : [];
            $partes[] = (string) ($institucion['organismo_comprador'] ?? '');
            $partes[] = (string) ($institucion['comuna'] ?? $institucion['nombre_comuna'] ?? '');
        }

        $haystack = $this->normalizarTextoBusqueda(implode(' ', $partes));
        if ($haystack === '') {
            return false;
        }

        $needle = $this->normalizarTextoBusqueda($frase);
        if ($needle === '') {
            return false;
        }

        // Frase completa (ej. "servicio de aseo").
        if (str_contains($haystack, $needle)) {
            return true;
        }

        // Frase multi-palabra: todas las palabras ≥3 chars deben aparecer.
        $tokens = array_values(array_filter(
            preg_split('/\s+/u', $needle) ?: [],
            static fn (string $t) => mb_strlen($t) >= 3,
        ));

        if ($tokens === []) {
            return false;
        }

        foreach ($tokens as $token) {
            if (! str_contains($haystack, $token)) {
                return false;
            }
        }

        return true;
    }

    private function normalizarTextoBusqueda(string $texto): string
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');
        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
        ]);
        $texto = preg_replace('/[^a-z0-9\s]+/u', ' ', $texto) ?? $texto;
        $texto = preg_replace('/\s+/u', ' ', $texto) ?? $texto;

        return trim($texto);
    }

    /**
     * Plan de consulta: una pareja región × frase por paso (para ir mostrando resultados).
     * Orden: MERCADOPUBLICO_REGIONES, luego prioridad de palabras clave (orden).
     *
     * @return array{
     *   palabras: list<string>,
     *   pasos: list<array{frase: string, region: int, region_nombre: string}>,
     *   total_pasos: int,
     *   fecha: string,
     *   api_configurada: bool,
     *   error: ?string
     * }
     */
    public function planBusqueda(): array
    {
        $palabras = $this->palabrasClave();
        $regiones = CompraAgilRegionScope::regionesIncluidas();
        $fecha = now()->timezone(config('app.timezone'))->toDateString();

        if ($palabras === []) {
            return [
                'palabras' => [],
                'pasos' => [],
                'total_pasos' => 0,
                'fecha' => $fecha,
                'api_configurada' => $this->api->isConfigured(),
                'error' => 'No hay palabras clave configuradas.',
            ];
        }

        if (! $this->api->isConfigured()) {
            return [
                'palabras' => $palabras,
                'pasos' => [],
                'total_pasos' => 0,
                'fecha' => $fecha,
                'api_configurada' => false,
                'error' => 'API Mercado Público no configurada. Defina MERCADOPUBLICO_TICKET en el servidor.',
            ];
        }

        // Región primero (orden MERCADOPUBLICO_REGIONES), luego cada palabra clave.
        $pasos = [];
        foreach ($regiones as $region) {
            foreach ($palabras as $frase) {
                $pasos[] = [
                    'frase' => $frase,
                    'region' => (int) $region,
                    'region_nombre' => CompraAgilRegionScope::nombreRegion((int) $region),
                ];
            }
        }

        return [
            'palabras' => $palabras,
            'pasos' => $pasos,
            'total_pasos' => count($pasos),
            'fecha' => $fecha,
            'api_configurada' => true,
            'error' => null,
        ];
    }

    /**
     * Parámetros de consulta a Mercado Público para un paso (frase × región).
     *
     * @return array<string, mixed>
     */
    public function parametrosConsultaPaso(string $frase, int $region): array
    {
        $frase = trim($frase);

        return [
            'estado' => 'publicada',
            'numero_pagina' => 1,
            'ordenar_por' => 'FechaPublicacion',
            'q' => $frase,
            'region' => max(1, $region),
            'tamano_pagina' => 50,
        ];
    }

    /**
     * Ejecuta un paso (frase × región) y devuelve solo publicadas hoy.
     * Omite códigos ya presentes en la lista acumulada o ya grabados hoy.
     * Graba en BD cada oportunidad nueva encontrada.
     *
     * @param  list<string>  $codigosExcluidos
     * @return array{
     *   items: list<array<string, mixed>>,
     *   consulta: array<string, mixed>,
     *   guardadas: int
     * }
     */
    public function ejecutarPaso(
        string $frase,
        int $region,
        array $codigosExcluidos = [],
        ?int $userId = null,
    ): array {
        $frase = trim($frase);
        if ($frase === '' || $region < 1) {
            return [
                'items' => [],
                'consulta' => $this->metaConsultaPaso($frase, $region, 0, 0),
                'guardadas' => 0,
            ];
        }

        if (! $this->api->isConfigured()) {
            throw new RuntimeException(
                'API Mercado Público no configurada. Defina MERCADOPUBLICO_TICKET en el servidor.'
            );
        }

        $yaVistos = [];
        foreach (array_merge($codigosExcluidos, $this->codigosGuardadosHoy()) as $codigo) {
            $norm = strtoupper(trim((string) $codigo));
            if ($norm !== '') {
                $yaVistos[$norm] = true;
            }
        }

        $dia = $this->fechaBusquedaHoy();
        $cacheKey = 'oportunidad_para_cotizar:'.$dia.':'.md5(mb_strtolower($frase).'|'.$region);
        $params = $this->parametrosConsultaPaso($frase, $region);

        $crudos = Cache::remember($cacheKey, self::CACHE_SEGUNDOS, function () use ($params) {
            $resultado = $this->api->listar($params);

            return $resultado['items'] ?? [];
        });

        $items = [];
        foreach ($crudos as $item) {
            if (! is_array($item) || CompraAgilRegionScope::debeExcluirItem($item)) {
                continue;
            }

            $codigo = strtoupper(trim((string) ($item['codigo'] ?? '')));
            if ($codigo === '') {
                continue;
            }

            $resumen = $this->oportunidad->enriquecerResumen(
                $this->mapper->resumenListadoItem($item),
            );

            // MP puede devolver resultados irrelevantes: exigir que la frase esté en el texto.
            if (! $this->fraseApareceEnTexto($frase, $resumen, $item)) {
                continue;
            }

            if (! $this->esPublicadaHoy($resumen['fecha_publicacion'] ?? null)) {
                continue;
            }

            if (isset($yaVistos[$codigo])) {
                $this->agregarPalabraAGuardada($codigo, $frase);
                $this->completarCantidadProductosGuardada($codigo);

                continue;
            }

            $regionItem = isset($resumen['region']) ? (int) $resumen['region'] : null;
            $resumen['palabras_coinciden'] = [$frase];
            $resumen['cantidad_productos'] = $this->obtenerCantidadProductosReal($codigo);
            $resumen['indice_region_config'] = CompraAgilRegionScope::indiceEnConfig($regionItem);
            $resumen['distancia_santiago'] = CompraAgilRegionScope::distanciaASantiago($regionItem);
            $resumen['guardada'] = true;
            $items[] = $resumen;
            $yaVistos[$codigo] = true;
        }

        $guardadas = $this->guardarEncontradas($items, $userId);

        return [
            'items' => $items,
            'consulta' => $this->metaConsultaPaso($frase, $region, count($crudos), count($items)),
            'guardadas' => $guardadas,
        ];
    }

    /**
     * Metadatos de depuración de la consulta MP (sin llamar a la API).
     *
     * @return array<string, mixed>
     */
    public function consultaDebugPaso(string $frase, int $region, ?int $totalApi = null, ?int $totalHoy = null): array
    {
        return $this->metaConsultaPaso(
            $frase,
            $region,
            $totalApi ?? 0,
            $totalHoy ?? 0,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function metaConsultaPaso(string $frase, int $region, int $totalApi, int $totalHoy): array
    {
        $params = $this->parametrosConsultaPaso($frase, $region);
        ksort($params);

        $baseUrl = rtrim((string) config('cotiz.mercadopublico.base_url'), '/');
        $path = '/v2/compra-agil';
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $paraJson = [
            'endpoint' => $baseUrl.$path,
            'filtro_fecha' => now()->timezone(config('app.timezone'))->toDateString(),
            'header_ticket' => '(configurado)',
            'metodo' => 'GET',
            'parametros' => $params,
            'total_api' => $totalApi,
            'total_publicadas_hoy' => $totalHoy,
            'url_completa' => $baseUrl.$path.'?'.$query,
        ];
        ksort($paraJson);

        return array_merge($paraJson, [
            'json' => json_encode(
                $paraJson,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ) ?: '{}',
        ]);
    }

    /**
     * Compatibilidad: búsqueda completa (sincrono). Solo publicadas hoy.
     *
     * @return array{
     *   items: list<array<string, mixed>>,
     *   palabras: list<string>,
     *   error: ?string,
     *   api_configurada: bool
     * }
     */
    public function listar(): array
    {
        $plan = $this->planBusqueda();

        if ($plan['error'] !== null) {
            return [
                'items' => [],
                'palabras' => $plan['palabras'],
                'error' => $plan['error'],
                'api_configurada' => $plan['api_configurada'],
            ];
        }

        try {
            $porCodigo = [];
            foreach ($plan['pasos'] as $paso) {
                $excluidos = array_keys($porCodigo);
                foreach ($this->ejecutarPaso($paso['frase'], $paso['region'], $excluidos)['items'] as $item) {
                    $codigo = strtoupper(trim((string) ($item['codigo'] ?? '')));
                    if ($codigo === '' || isset($porCodigo[$codigo])) {
                        continue;
                    }
                    $porCodigo[$codigo] = $item;
                }
            }

            $items = array_values($porCodigo);
            usort($items, [$this, 'compararOportunidades']);

            return [
                'items' => $items,
                'palabras' => $plan['palabras'],
                'error' => null,
                'api_configurada' => true,
            ];
        } catch (RuntimeException $e) {
            return [
                'items' => [],
                'palabras' => $plan['palabras'],
                'error' => $e->getMessage(),
                'api_configurada' => true,
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public function compararOportunidades(array $a, array $b): int
    {
        $regionA = isset($a['region']) ? (int) $a['region'] : null;
        $regionB = isset($b['region']) ? (int) $b['region'] : null;
        $idxA = (int) ($a['indice_region_config'] ?? CompraAgilRegionScope::indiceEnConfig($regionA));
        $idxB = (int) ($b['indice_region_config'] ?? CompraAgilRegionScope::indiceEnConfig($regionB));
        if ($idxA !== $idxB) {
            return $idxA <=> $idxB;
        }

        $montoA = (int) ($a['monto_presupuesto_clp'] ?? 0);
        $montoB = (int) ($b['monto_presupuesto_clp'] ?? 0);
        if ($montoA !== $montoB) {
            return $montoB <=> $montoA;
        }

        $distA = (int) ($a['distancia_santiago'] ?? CompraAgilRegionScope::distanciaASantiago($regionA));
        $distB = (int) ($b['distancia_santiago'] ?? CompraAgilRegionScope::distanciaASantiago($regionB));

        return $distA <=> $distB;
    }

    public function esPublicadaHoy(mixed $fecha): bool
    {
        $fecha = trim((string) $fecha);
        if ($fecha === '') {
            return false;
        }

        try {
            return Carbon::parse($fecha)
                ->timezone(config('app.timezone'))
                ->isToday();
        } catch (\Throwable) {
            return false;
        }
    }
}

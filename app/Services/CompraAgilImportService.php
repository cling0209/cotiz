<?php

namespace App\Services;

use App\Models\AgileMaeprod;
use App\Models\Nota;
use App\Models\OportunidadEncontrada;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CompraAgilImportService
{
    public function __construct(
        protected CompraAgilTextoParserService $parser,
        protected NotaService $notaService,
        protected NotaDetalleService $detalleService,
        protected AgileMaeprodService $agileMaeprodService,
        protected AgileVinculoAprendizajeService $vinculoAprendizaje,
        protected CompraAgilOportunidadService $oportunidad,
    ) {}

    /**
     * @return array{
     *   cabecera: array{codigo_cotizacion: string, empresa: string, rutempresa: string, nombre: string},
     *   lineas: array<int, array{
     *     id_agile: string,
     *     descripcion: string,
     *     cantidad: int,
     *     categoria: string,
     *     producto: ?array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int},
     *     estado: string,
     *     es_sugerencia: bool
     *   }>,
     *   resumen: array{total: int, vinculados: int, pendientes: int, con_sugerencia: int}
     * }
     */
    public function preview(string $texto): array
    {
        $total = count($this->parser->parse($texto)['lineas']);
        $resultado = $this->previewLote($texto, 0, $total);
        unset($resultado['total'], $resultado['procesadas'], $resultado['completado']);

        return $resultado;
    }

    /**
     * Preview desde datos estructurados (API Mercado Público).
     *
     * @param  array{
     *   cabecera: array{codigo_cotizacion: string, empresa: string, rutempresa: string, nombre: string},
     *   lineas: array<int, array{id_agile: string, descripcion: string, cantidad: int, categoria: string}>
     * }  $datos
     */
    public function previewDesdeDatos(array $datos): array
    {
        $total = count($datos['lineas']);
        $resultado = $this->previewLoteDesdeDatos($datos, 0, $total);
        unset($resultado['total'], $resultado['procesadas'], $resultado['completado']);

        return $resultado;
    }

    /**
     * @param  array{
     *   cabecera: array{codigo_cotizacion: string, empresa: string, rutempresa: string, nombre: string},
     *   lineas: array<int, array{id_agile: string, descripcion: string, cantidad: int, categoria: string}>
     * }  $datos
     */
    public function previewLoteDesdeDatos(array $datos, int $desde, int $hasta): array
    {
        if ($desde === 0) {
            $this->vinculoAprendizaje->precalentarParaImportacion();
        }

        $total = count($datos['lineas']);
        $hasta = max($desde, min($hasta, $total));

        $lineas = [];
        for ($i = $desde; $i < $hasta; $i++) {
            $lineas[] = $this->construirLineaPreview($datos['lineas'][$i]);
        }

        $resultado = [
            'lineas' => $lineas,
            'total' => $total,
            'procesadas' => $hasta,
            'completado' => $hasta >= $total,
            'resumen' => $this->resumenDesdeLineasPreview($lineas),
        ];

        if ($desde === 0) {
            $resultado['cabecera'] = $datos['cabecera'];
        }

        return $resultado;
    }

    /**
     * @param  array{
     *   cabecera: array{codigo_cotizacion: string, empresa: string, rutempresa: string, nombre: string},
     *   lineas: array<int, array{id_agile: string, descripcion: string, cantidad: int, categoria: string}>
     * }  $datos
     * @return array{agregadas: int, vinculadas: int, pendientes: int, cabecera_actualizada: bool, mensajes: string[]}
     */
    public function aplicarDesdeDatos(Nota $nota, array $datos, string $usuario): array
    {
        $total = count($datos['lineas']);
        $resultado = $this->aplicarConPreview($nota, $datos, $usuario, 0, $total);
        unset($resultado['total'], $resultado['procesadas'], $resultado['completado']);

        return $resultado;
    }

    /**
     * @param  array{
     *   cabecera: array{codigo_cotizacion: string, empresa: string, rutempresa: string, nombre: string},
     *   lineas: array<int, array{id_agile: string, descripcion: string, cantidad: int, categoria: string}>
     * }  $datos
     */
    public function aplicarLoteDesdeDatos(Nota $nota, array $datos, string $usuario, int $desde, int $hasta): array
    {
        $total = count($datos['lineas']);
        $hasta = max($desde, min($hasta, $total));

        return $this->aplicarConPreview($nota, $datos, $usuario, $desde, $hasta);
    }

    /**
     * Importa desde un preview ya analizado (p. ej. vínculo de Oportunidades), sin reconsultar MP.
     *
     * @param  array{
     *   cabecera?: array<string, mixed>,
     *   lineas: array<int, array<string, mixed>>
     * }  $datos
     * @return array<string, mixed>
     */
    public function aplicarLoteDesdePreview(Nota $nota, array $datos, string $usuario, int $desde, int $hasta): array
    {
        $normalizado = $this->normalizarDatosPreview($datos);
        $total = count($normalizado['lineas']);
        $hasta = max($desde, min($hasta, $total));

        return $this->aplicarConPreview(
            $nota,
            $normalizado,
            $usuario,
            $desde,
            $hasta,
            omitirValidacionMp: true,
        );
    }

    /**
     * @param  array{
     *   cabecera?: array<string, mixed>,
     *   lineas: array<int, array<string, mixed>>
     * }  $datos
     * @return array{
     *   cabecera: array<string, mixed>,
     *   lineas: array<int, array<string, mixed>>
     * }
     */
    public function normalizarDatosPreview(array $datos): array
    {
        $cabeceraIn = is_array($datos['cabecera'] ?? null) ? $datos['cabecera'] : [];
        $lineas = [];

        foreach ($datos['lineas'] ?? [] as $fila) {
            if (! is_array($fila)) {
                continue;
            }
            $descripcion = trim((string) ($fila['descripcion'] ?? ''));
            if ($descripcion === '') {
                continue;
            }

            $linea = [
                'id_agile' => mb_substr(trim((string) ($fila['id_agile'] ?? '')), 0, 50),
                'descripcion' => mb_substr($descripcion, 0, 500),
                'cantidad' => max(1, (int) ($fila['cantidad'] ?? 1)),
                'categoria' => trim((string) ($fila['categoria'] ?? '')),
            ];

            if (isset($fila['estado'])) {
                $linea['estado'] = (string) $fila['estado'];
            }
            if (array_key_exists('es_sugerencia', $fila)) {
                $linea['es_sugerencia'] = (bool) $fila['es_sugerencia'];
            }
            if (isset($fila['producto']) && is_array($fila['producto'])) {
                $linea['producto'] = $fila['producto'];
            }

            $lineas[] = $linea;
        }

        $region = isset($cabeceraIn['region']) && is_numeric($cabeceraIn['region'])
            ? (int) $cabeceraIn['region']
            : null;
        if ($region !== null && $region <= 0) {
            $region = null;
        }

        return [
            'cabecera' => [
                'codigo_cotizacion' => trim((string) ($cabeceraIn['codigo_cotizacion'] ?? '')),
                'empresa' => trim((string) ($cabeceraIn['empresa'] ?? '')),
                'rutempresa' => trim((string) ($cabeceraIn['rutempresa'] ?? '')),
                'nombre' => trim((string) ($cabeceraIn['nombre'] ?? '')),
                'region' => $region,
                'nombre_region' => trim((string) ($cabeceraIn['nombre_region'] ?? '')),
                'comuna' => trim((string) ($cabeceraIn['comuna'] ?? '')),
                'direccion_entrega' => trim((string) ($cabeceraIn['direccion_entrega'] ?? '')),
            ],
            'lineas' => $lineas,
        ];
    }

    /**
     * Analiza un rango de líneas con sugerencias por similitud (0-based, hasta exclusivo).
     *
     * @return array{
     *   cabecera?: array{codigo_cotizacion: string, empresa: string, rutempresa: string, nombre: string},
     *   lineas: array<int, array<string, mixed>>,
     *   resumen: array{total: int, vinculados: int, pendientes: int, con_sugerencia: int},
     *   total: int,
     *   procesadas: int,
     *   completado: bool
     * }
     */
    public function previewLote(string $texto, int $desde, int $hasta): array
    {
        $parsed = $this->parser->parse($texto);
        $total = count($parsed['lineas']);
        $hasta = max($desde, min($hasta, $total));

        $lineas = [];
        for ($i = $desde; $i < $hasta; $i++) {
            $lineas[] = $this->construirLineaPreview($parsed['lineas'][$i]);
        }

        $resultado = [
            'lineas' => $lineas,
            'total' => $total,
            'procesadas' => $hasta,
            'completado' => $hasta >= $total,
            'resumen' => $this->resumenDesdeLineasPreview($lineas),
        ];

        if ($desde === 0) {
            $resultado['cabecera'] = $this->cabeceraDesdeParsed($parsed);
        }

        return $resultado;
    }

    /**
     * @return array<int, string>
     */
    public function idsAgileDelTexto(string $texto): array
    {
        $parsed = $this->parser->parse($texto);

        return array_values(array_map(
            static fn (array $linea) => (string) $linea['id_agile'],
            $parsed['lineas'],
        ));
    }

    /**
     * @return array{
     *   cabecera: array{codigo_cotizacion: string, empresa: string, rutempresa: string, nombre: string},
     *   lineas: array<int, array{id_agile: string, descripcion: string, cantidad: int, categoria: string}>
     * }
     */
    public function parseTexto(string $texto): array
    {
        return $this->parseParaImport($texto);
    }

    /**
     * @param  array{id_agile: string, descripcion: string, cantidad: int, categoria: string}  $linea
     * @return array{
     *   id_agile: string,
     *   descripcion: string,
     *   cantidad: int,
     *   categoria: string,
     *   producto: ?array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int},
     *   estado: string,
     *   es_sugerencia: bool
     * }
     */
    private function construirLineaPreview(array $linea): array
    {
        $resuelto = $this->vinculoAprendizaje->resolverParaImportacion($linea['descripcion']);

        return [
            'id_agile' => $linea['id_agile'],
            'descripcion' => $linea['descripcion'],
            'cantidad' => $linea['cantidad'],
            'categoria' => $linea['categoria'],
            'producto' => $resuelto['producto'],
            'estado' => $resuelto['estado'],
            'es_sugerencia' => $resuelto['es_sugerencia'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lineas
     * @return array{total: int, vinculados: int, pendientes: int, con_sugerencia: int}
     */
    public function resumenDesdeLineasPreview(array $lineas): array
    {
        $vinculados = 0;
        $conSugerencia = 0;

        foreach ($lineas as $linea) {
            if (($linea['estado'] ?? '') === 'vinculado') {
                $vinculados++;
            }
            if (! empty($linea['es_sugerencia'])) {
                $conSugerencia++;
            }
        }

        $total = count($lineas);

        return [
            'total' => $total,
            'vinculados' => $vinculados,
            'pendientes' => $total - $vinculados,
            'con_sugerencia' => $conSugerencia,
        ];
    }

    /**
     * @return array{agregadas: int, vinculadas: int, pendientes: int, cabecera_actualizada: bool, mensajes: string[]}
     */
    public function aplicar(Nota $nota, string $texto, string $usuario): array
    {
        $datos = $this->parseParaImport($texto);
        $total = count($datos['lineas']);
        $resultado = $this->aplicarConPreview($nota, $datos, $usuario, 0, $total);
        unset($resultado['total'], $resultado['procesadas'], $resultado['completado']);

        return $resultado;
    }

    /**
     * Importa un rango de líneas (0-based, hasta exclusivo). En el primer lote (desde=0) valida y actualiza cabecera.
     *
     * @return array{
     *   agregadas: int,
     *   vinculadas: int,
     *   pendientes: int,
     *   cabecera_actualizada: bool,
     *   mensajes: string[],
     *   total: int,
     *   procesadas: int,
     *   completado: bool
     * }
     */
    public function aplicarLote(Nota $nota, string $texto, string $usuario, int $desde, int $hasta): array
    {
        $datos = $this->parseParaImport($texto);
        $total = count($datos['lineas']);
        $hasta = max($desde, min($hasta, $total));

        return $this->aplicarConPreview($nota, $datos, $usuario, $desde, $hasta);
    }

    /**
     * Parseo rápido para importar (sin búsqueda por similitud).
     *
     * @return array{
     *   cabecera: array{codigo_cotizacion: string, empresa: string, rutempresa: string, nombre: string},
     *   lineas: array<int, array{id_agile: string, descripcion: string, cantidad: int, categoria: string}>
     * }
     */
    private function parseParaImport(string $texto): array
    {
        $parsed = $this->parser->parse($texto);
        $lineas = [];

        foreach ($parsed['lineas'] as $linea) {
            $lineas[] = [
                'id_agile' => $linea['id_agile'],
                'descripcion' => $linea['descripcion'],
                'cantidad' => $linea['cantidad'],
                'categoria' => $linea['categoria'],
            ];
        }

        return [
            'cabecera' => $this->cabeceraDesdeParsed($parsed),
            'lineas' => $lineas,
        ];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array{
     *   codigo_cotizacion: string,
     *   empresa: string,
     *   rutempresa: string,
     *   nombre: string,
     *   region: ?int,
     *   nombre_region: string,
     *   comuna: string,
     *   direccion_entrega: string
     * }
     */
    private function cabeceraDesdeParsed(array $parsed): array
    {
        $region = isset($parsed['region']) && is_numeric($parsed['region'])
            ? (int) $parsed['region']
            : null;
        if ($region !== null && $region <= 0) {
            $region = null;
        }

        return [
            'codigo_cotizacion' => (string) ($parsed['codigo_cotizacion'] ?? ''),
            'empresa' => (string) ($parsed['empresa'] ?? ''),
            'rutempresa' => (string) ($parsed['rutempresa'] ?? ''),
            'nombre' => (string) ($parsed['nombre'] ?? ''),
            'region' => $region,
            'nombre_region' => (string) ($parsed['nombre_region'] ?? ''),
            'comuna' => (string) ($parsed['comuna'] ?? ''),
            'direccion_entrega' => (string) ($parsed['direccion_entrega'] ?? ''),
        ];
    }

    /**
     * Completa geo faltante desde oportunidad_encontradas (proceso Oportunidades).
     *
     * @param  array{
     *   cabecera: array<string, mixed>,
     *   lineas: array<int, array<string, mixed>>
     * }  $datos
     * @return array{
     *   cabecera: array<string, mixed>,
     *   lineas: array<int, array<string, mixed>>
     * }
     */
    public function enriquecerCabeceraDesdeOportunidad(array $datos): array
    {
        $cabecera = is_array($datos['cabecera'] ?? null) ? $datos['cabecera'] : [];
        $codigo = strtoupper(trim((string) ($cabecera['codigo_cotizacion'] ?? '')));
        if ($codigo === '') {
            return $datos;
        }

        $opp = OportunidadEncontrada::query()
            ->where('codigo', $codigo)
            ->orderByDesc('fecha_busqueda')
            ->orderByDesc('id')
            ->first();

        if (! $opp) {
            return $datos;
        }

        if (empty($cabecera['region']) && $opp->region) {
            $cabecera['region'] = (int) $opp->region;
        }
        if (trim((string) ($cabecera['nombre_region'] ?? '')) === '' && trim((string) ($opp->nombre_region ?? '')) !== '') {
            $cabecera['nombre_region'] = (string) $opp->nombre_region;
        }
        if (trim((string) ($cabecera['comuna'] ?? '')) === '' && trim((string) ($opp->comuna ?? '')) !== '') {
            $cabecera['comuna'] = (string) $opp->comuna;
        }
        if (trim((string) ($cabecera['direccion_entrega'] ?? '')) === '' && trim((string) ($opp->direccion ?? '')) !== '') {
            $cabecera['direccion_entrega'] = (string) $opp->direccion;
        }
        if (trim((string) ($cabecera['nombre_region'] ?? '')) === '' && ! empty($cabecera['region'])) {
            $cabecera['nombre_region'] = CompraAgilRegionScope::nombreRegion((int) $cabecera['region']);
        }

        $datos['cabecera'] = $cabecera;

        return $datos;
    }

    /**
     * @param  array{
     *   cabecera: array{codigo_cotizacion: string, empresa: string, rutempresa: string, nombre: string},
     *   lineas: array<int, array<string, mixed>>,
     *   resumen: array<string, int>
     * }  $preview
     * @return array{
     *   agregadas: int,
     *   vinculadas: int,
     *   pendientes: int,
     *   cabecera_actualizada: bool,
     *   mensajes: string[],
     *   total: int,
     *   procesadas: int,
     *   completado: bool
     * }
     */
    private function aplicarConPreview(
        Nota $nota,
        array $preview,
        string $usuario,
        int $desde,
        int $hasta,
        bool $omitirValidacionMp = false,
    ): array {
        return DB::transaction(function () use ($nota, $preview, $usuario, $desde, $hasta, $omitirValidacionMp) {
            $mensajes = [];
            $total = count($preview['lineas']);

            if ($desde === 0 && ($preview['cabecera']['codigo_cotizacion'] ?? '') === '' && $preview['lineas'] === []) {
                throw new RuntimeException('No se detectó información de Compra Ágil para importar.');
            }

            $cabeceraActualizada = false;

            if ($desde === 0) {
                $preview = $this->enriquecerCabeceraDesdeOportunidad($preview);
                $cab = $preview['cabecera'];
                $datosCabecera = [];

                if (($cab['codigo_cotizacion'] ?? '') !== '') {
                    $datosCabecera['encargado'] = $cab['codigo_cotizacion'];
                }
                if (($cab['empresa'] ?? '') !== '') {
                    $datosCabecera['empresa'] = $cab['empresa'];
                }
                if (($cab['rutempresa'] ?? '') !== '') {
                    $datosCabecera['rutempresa'] = $cab['rutempresa'];
                }
                if (($cab['nombre'] ?? '') !== '') {
                    $datosCabecera['descripcion'] = $cab['nombre'];
                }

                $region = isset($cab['region']) && is_numeric($cab['region']) ? (int) $cab['region'] : null;
                if ($region !== null && $region > 0) {
                    $datosCabecera['region'] = $region;
                    $nombreRegion = trim((string) ($cab['nombre_region'] ?? ''));
                    $datosCabecera['nombre_region'] = $nombreRegion !== ''
                        ? $nombreRegion
                        : CompraAgilRegionScope::nombreRegion($region);

                    $factorRegion = CompraAgilRegionScope::factorPrecioVentaPorRegion($region);
                    if ($factorRegion !== null) {
                        $datosCabecera['factor_precio_venta'] = $factorRegion;
                    }

                    $dias = CompraAgilRegionScope::diasHabilesPorRegion($region);
                    if ($dias !== null) {
                        $datosCabecera['diashabiles'] = $dias;
                    }
                }

                if (trim((string) ($cab['comuna'] ?? '')) !== '') {
                    $datosCabecera['comuna'] = mb_substr(trim((string) $cab['comuna']), 0, 120);
                }
                if (trim((string) ($cab['direccion_entrega'] ?? '')) !== '') {
                    $datosCabecera['direccion_entrega'] = mb_substr(trim((string) $cab['direccion_entrega']), 0, 255);
                }

                if ($datosCabecera !== []) {
                    if (isset($datosCabecera['encargado'])) {
                        // Consulta sitio par (si ya se verificó en este request/lote, se omite).
                        $error = $this->notaService->validarNumeroCotizacionDisponible(
                            $nota,
                            $datosCabecera['encargado'],
                            true,
                            $omitirValidacionMp,
                        );
                        if ($error !== null) {
                            throw new RuntimeException($error);
                        }
                        if (! $omitirValidacionMp) {
                            $this->oportunidad->assertExisteEnMpSiCompraAgil($datosCabecera['encargado']);
                        }
                    }
                    $this->notaService->modificarCabecera($nota, $datosCabecera, $usuario);
                    $cabeceraActualizada = true;
                    $nota = $nota->fresh();
                }
            }

            $agregadas = 0;
            $vinculadas = 0;
            $pendientes = 0;
            $loteInsert = [];

            for ($i = $desde; $i < $hasta; $i++) {
                $linea = $preview['lineas'][$i];
                $descripcionMp = $this->descripcionAgileParaLinea($linea['id_agile'], $linea['descripcion']);

                // Preview cacheado: confiar en vínculos firmes ya resueltos; no re-scorear.
                // Sin preview: solo aprendizaje firme (nunca auto-aplica similitud maeprod).
                $vinculo = $this->productoDesdePreviewLinea($linea);
                if ($vinculo === null && ! $omitirValidacionMp) {
                    $vinculo = $this->resolverProductoParaImportar($linea['descripcion']);
                }

                if ($vinculo) {
                    $loteInsert[] = [
                        'pendiente' => false,
                        'prod_item' => $vinculo['prod_item'],
                        'cantidad' => (int) $linea['cantidad'],
                        'prod_valor' => (int) $vinculo['prod_valor'],
                        'prod_valor_costo' => (int) $vinculo['prod_valor_costo'],
                        'prod_item_agile' => $linea['id_agile'],
                        'prod_descripcion_agile' => $descripcionMp,
                        'prod_nombre' => $vinculo['prod_nombre'] ?? null,
                    ];
                    $vinculadas++;
                } else {
                    $loteInsert[] = [
                        'pendiente' => true,
                        'cantidad' => (int) $linea['cantidad'],
                        'prod_item_agile' => $linea['id_agile'],
                        'prod_descripcion_agile' => $descripcionMp,
                    ];
                    $pendientes++;
                    $mensajes[] = 'Pendiente de vincular: '.$linea['descripcion'];
                }

                $agregadas++;
            }

            if ($loteInsert !== []) {
                $this->detalleService->agregarLineasImportacionLote($nota, $loteInsert);
            }

            if ($desde === 0 && $hasta >= $total && $agregadas === 0) {
                throw new RuntimeException('No se detectaron productos para importar.');
            }

            if ($hasta >= $total) {
                $notaFresh = $nota->fresh();
                $factorRegion = CompraAgilRegionScope::factorPrecioVentaPorRegion(
                    $notaFresh?->region !== null ? (int) $notaFresh->region : null
                );
                if ($factorRegion !== null) {
                    $this->detalleService->aplicarFactorPrecioVenta($notaFresh, $factorRegion, $usuario);
                }
            }

            return [
                'agregadas' => $agregadas,
                'vinculadas' => $vinculadas,
                'pendientes' => $pendientes,
                'cabecera_actualizada' => $cabeceraActualizada,
                'mensajes' => $mensajes,
                'total' => $total,
                'procesadas' => $hasta,
                'completado' => $hasta >= $total,
            ];
        });
    }

    /**
     * Descripción Mercado Público: texto parseado o, si falta, la guardada en agilemaeprod.
     */
    private function descripcionAgileParaLinea(string $idAgile, string $descripcionParseada): string
    {
        $desc = trim($descripcionParseada);
        if ($desc !== '') {
            return $desc;
        }

        $row = AgileMaeprod::query()->find(trim($idAgile));

        return trim((string) ($row->prod_descripcion_agile ?? ''));
    }

    /**
     * Reutiliza el producto ya resuelto en el preview (evita re-score en importación).
     *
     * @param  array<string, mixed>  $linea
     * @return ?array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}
     */
    private function productoDesdePreviewLinea(array $linea): ?array
    {
        if (($linea['estado'] ?? '') !== 'vinculado' || ! empty($linea['es_sugerencia'])) {
            return null;
        }

        $producto = $linea['producto'] ?? null;
        if (! is_array($producto)) {
            return null;
        }

        $prodItem = trim((string) ($producto['prod_item'] ?? ''));
        if ($prodItem === '') {
            return null;
        }

        return [
            'prod_item' => $prodItem,
            'prod_nombre' => (string) ($producto['prod_nombre'] ?? ''),
            'prod_valor' => (int) ($producto['prod_valor'] ?? 0),
            'prod_valor_costo' => (int) ($producto['prod_valor_costo'] ?? 0),
        ];
    }

    /**
     * Solo vínculo firme (aprendizaje). No corre similitud maeprod:
     * las sugerencias quedan como NOK-{orden} para vincular después con Buscar.
     *
     * @return ?array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}
     */
    private function resolverProductoParaImportar(string $descripcion): ?array
    {
        return $this->vinculoAprendizaje->buscarCodigoAprendido($descripcion);
    }
}

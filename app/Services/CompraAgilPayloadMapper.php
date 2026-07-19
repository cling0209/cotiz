<?php

namespace App\Services;

/**
 * Convierte payload de detalle API Compra Ágil al formato interno de importación.
 */
class CompraAgilPayloadMapper
{
    public function __construct(
        protected CompraAgilTextoParserService $parser,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *   cabecera: array{
     *     codigo_cotizacion: string,
     *     empresa: string,
     *     rutempresa: string,
     *     nombre: string,
     *     region: ?int,
     *     nombre_region: string,
     *     comuna: string,
     *     direccion_entrega: string
     *   },
     *   lineas: array<int, array{id_agile: string, descripcion: string, cantidad: int, categoria: string}>
     * }
     */
    public function fromDetalle(array $payload): array
    {
        $codigo = strtoupper(trim((string) ($payload['codigo'] ?? '')));
        $institucion = is_array($payload['institucion'] ?? null) ? $payload['institucion'] : [];
        $empresa = trim((string) ($institucion['organismo_comprador'] ?? ''));
        $rut = isset($institucion['rut']) ? $this->parser->normalizarRut((string) $institucion['rut']) : '';
        $nombre = trim((string) ($payload['nombre'] ?? ''));
        if ($nombre === '') {
            $nombre = trim((string) ($payload['descripcion'] ?? ''));
        }

        $geo = $this->geoDesdeInstitucion($institucion, $payload);

        $lineas = [];
        $productos = $payload['productos_solicitados'] ?? [];
        if (is_array($productos)) {
            foreach ($productos as $producto) {
                if (! is_array($producto)) {
                    continue;
                }
                $idAgile = trim((string) ($producto['codigo_producto'] ?? ''));
                $nombreProd = trim((string) ($producto['nombre'] ?? ''));
                $descripcion = trim((string) ($producto['descripcion'] ?? ''));
                if ($descripcion === '') {
                    $descripcion = $nombreProd;
                }
                $categoria = $nombreProd !== '' ? $nombreProd : $descripcion;
                $cantidad = max(1, (int) round((float) ($producto['cantidad'] ?? 1)));

                if ($idAgile === '' && $descripcion === '') {
                    continue;
                }

                $lineas[] = [
                    'id_agile' => $idAgile !== '' ? $idAgile : md5($descripcion.$cantidad),
                    'descripcion' => mb_substr($descripcion, 0, 500),
                    'cantidad' => $cantidad,
                    'categoria' => mb_substr($categoria, 0, 200),
                ];
            }
        }

        return [
            'cabecera' => [
                'codigo_cotizacion' => $codigo,
                'empresa' => mb_substr($empresa, 0, 100),
                'rutempresa' => $rut,
                'nombre' => mb_substr($nombre, 0, 500),
                'region' => $geo['region'],
                'nombre_region' => $geo['nombre_region'],
                'comuna' => $geo['comuna'],
                'direccion_entrega' => $geo['direccion'],
            ],
            'lineas' => $lineas,
        ];
    }

    /**
     * @param  array<string, mixed>  $item  ítem del listado API
     * @return array<string, mixed>
     */
    public function resumenListadoItem(array $item): array
    {
        $institucion = is_array($item['institucion'] ?? null) ? $item['institucion'] : [];
        $montos = is_array($item['montos'] ?? null) ? $item['montos'] : [];
        $fechas = is_array($item['fechas'] ?? null) ? $item['fechas'] : [];
        $estado = is_array($item['estado'] ?? null) ? $item['estado'] : [];
        $resumen = is_array($item['resumen'] ?? null) ? $item['resumen'] : [];
        $geo = $this->geoDesdeInstitucion($institucion, $item);

        return [
            'codigo' => strtoupper(trim((string) ($item['codigo'] ?? ''))),
            'nombre' => trim((string) ($item['nombre'] ?? '')),
            'organismo' => trim((string) ($institucion['organismo_comprador'] ?? '')),
            'rut_organismo' => isset($institucion['rut']) ? $this->parser->normalizarRut((string) $institucion['rut']) : '',
            'region' => $geo['region'],
            'nombre_region' => $geo['nombre_region'] !== ''
                ? $geo['nombre_region']
                : trim((string) ($institucion['nombre_region'] ?? '')),
            'comuna' => $geo['comuna'] !== ''
                ? $geo['comuna']
                : trim((string) ($institucion['comuna'] ?? $institucion['nombre_comuna'] ?? '')),
            'direccion' => $geo['direccion'],
            'monto_presupuesto_clp' => isset($montos['monto_disponible_clp'])
                ? (int) round((float) $montos['monto_disponible_clp'])
                : null,
            'moneda' => trim((string) ($montos['moneda'] ?? 'CLP')),
            'fecha_cierre' => trim((string) ($fechas['fecha_cierre'] ?? '')),
            'fecha_publicacion' => trim((string) ($fechas['fecha_publicacion'] ?? '')),
            'estado_codigo' => trim((string) ($estado['codigo'] ?? '')),
            'estado_glosa' => trim((string) ($estado['glosa'] ?? '')),
            'total_ofertas' => (int) ($resumen['total_ofertas_recibidas'] ?? 0),
            'cantidad_productos' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload  detalle API
     */
    public function cantidadProductosDetalle(array $payload): int
    {
        $productos = $payload['productos_solicitados'] ?? [];

        return is_array($productos) ? count($productos) : 0;
    }

    /**
     * @param  array<string, mixed>  $institucion
     * @param  array<string, mixed>  $contexto  payload detalle o ítem listado
     * @return array{region: ?int, nombre_region: string, comuna: string, direccion: string}
     */
    public function geoDesdeInstitucion(array $institucion, array $contexto = []): array
    {
        $region = null;
        if (isset($institucion['region']) && is_numeric($institucion['region'])) {
            $region = (int) $institucion['region'];
        } elseif (isset($contexto['region']) && is_numeric($contexto['region'])) {
            $region = (int) $contexto['region'];
        }
        if ($region !== null && $region <= 0) {
            $region = null;
        }

        $nombreRegion = trim((string) (
            $institucion['nombre_region']
            ?? $institucion['region_nombre']
            ?? $contexto['nombre_region']
            ?? ''
        ));
        if ($nombreRegion === '' && $region !== null) {
            $nombreRegion = CompraAgilRegionScope::nombreRegion($region);
        }

        $comuna = trim((string) (
            $institucion['comuna']
            ?? $institucion['nombre_comuna']
            ?? $institucion['comuna_unidad']
            ?? $contexto['comuna']
            ?? ''
        ));

        $direccion = $this->primeraCadenaNoVacia([
            $institucion['direccion'] ?? null,
            $institucion['dirección'] ?? null,
            $institucion['direccion_entrega'] ?? null,
            $institucion['direccion_unidad'] ?? null,
            $institucion['lugar_entrega'] ?? null,
            $institucion['domicilio'] ?? null,
            $contexto['direccion'] ?? null,
            $contexto['direccion_entrega'] ?? null,
            $contexto['lugar_entrega'] ?? null,
        ]);

        return [
            'region' => $region,
            'nombre_region' => mb_substr($nombreRegion, 0, 100),
            'comuna' => mb_substr($comuna, 0, 120),
            'direccion' => mb_substr($direccion, 0, 255),
        ];
    }

    /**
     * @param  list<mixed>  $candidatos
     */
    private function primeraCadenaNoVacia(array $candidatos): string
    {
        foreach ($candidatos as $valor) {
            $texto = trim((string) $valor);
            if ($texto !== '') {
                return $texto;
            }
        }

        return '';
    }
}

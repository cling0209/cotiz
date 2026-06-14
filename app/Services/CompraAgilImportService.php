<?php

namespace App\Services;

use App\Models\AgileMaeprod;
use App\Models\Maeprod;
use App\Models\Nota;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CompraAgilImportService
{
    public function __construct(
        protected CompraAgilTextoParserService $parser,
        protected MaeprodBusquedaSimilitudService $busqueda,
        protected NotaService $notaService,
        protected NotaDetalleService $detalleService,
        protected AgileMaeprodService $agileMaeprodService,
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
            $resultado['cabecera'] = [
                'codigo_cotizacion' => $parsed['codigo_cotizacion'],
                'empresa' => $parsed['empresa'],
                'rutempresa' => $parsed['rutempresa'],
                'nombre' => $parsed['nombre'],
            ];
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
        $vinculo = $this->resolverVinculoExistente($linea['id_agile']);
        $sugerencia = $vinculo ? null : $this->resolverSugerenciaSimilitud($linea['descripcion']);
        $producto = $vinculo ?? $sugerencia;

        return [
            'id_agile' => $linea['id_agile'],
            'descripcion' => $linea['descripcion'],
            'cantidad' => $linea['cantidad'],
            'categoria' => $linea['categoria'],
            'producto' => $producto,
            'estado' => $vinculo ? 'vinculado' : 'pendiente',
            'es_sugerencia' => $vinculo === null && $sugerencia !== null,
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
            'cabecera' => [
                'codigo_cotizacion' => $parsed['codigo_cotizacion'],
                'empresa' => $parsed['empresa'],
                'rutempresa' => $parsed['rutempresa'],
                'nombre' => $parsed['nombre'],
            ],
            'lineas' => $lineas,
        ];
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
    private function aplicarConPreview(Nota $nota, array $preview, string $usuario, int $desde, int $hasta): array
    {
        return DB::transaction(function () use ($nota, $preview, $usuario, $desde, $hasta) {
            $mensajes = [];
            $total = count($preview['lineas']);

            if ($desde === 0 && $preview['cabecera']['codigo_cotizacion'] === '' && $preview['lineas'] === []) {
                throw new RuntimeException('No se detectó información de Compra Ágil en el texto pegado.');
            }

            $cabeceraActualizada = false;

            if ($desde === 0) {
                $datosCabecera = [];

                if ($preview['cabecera']['codigo_cotizacion'] !== '') {
                    $datosCabecera['encargado'] = $preview['cabecera']['codigo_cotizacion'];
                }
                if ($preview['cabecera']['empresa'] !== '') {
                    $datosCabecera['empresa'] = $preview['cabecera']['empresa'];
                }
                if ($preview['cabecera']['rutempresa'] !== '') {
                    $datosCabecera['rutempresa'] = $preview['cabecera']['rutempresa'];
                }
                if ($preview['cabecera']['nombre'] !== '') {
                    $datosCabecera['descripcion'] = $preview['cabecera']['nombre'];
                }

                if ($datosCabecera !== []) {
                    if (isset($datosCabecera['encargado'])) {
                        $error = $this->notaService->validarNumeroCotizacion($nota, $datosCabecera['encargado']);
                        if ($error !== null) {
                            throw new RuntimeException($error);
                        }
                    }
                    $this->notaService->modificarCabecera($nota, $datosCabecera);
                    $cabeceraActualizada = true;
                    $nota = $nota->fresh();
                }
            }

            $agregadas = 0;
            $vinculadas = 0;
            $pendientes = 0;

            for ($i = $desde; $i < $hasta; $i++) {
                $linea = $preview['lineas'][$i];
                $descripcionMp = $this->descripcionAgileParaLinea($linea['id_agile'], $linea['descripcion']);
                $this->agileMaeprodService->registrarSiNoExiste($linea['id_agile'], $descripcionMp);

                $vinculo = $this->resolverProductoParaImportar($linea['id_agile'], $linea['descripcion']);

                if ($vinculo) {
                    $this->agileMaeprodService->vincularCodigoInterno($linea['id_agile'], $vinculo['prod_item']);

                    $this->detalleService->agregarLinea(
                        $nota,
                        $vinculo['prod_item'],
                        (int) $linea['cantidad'],
                        (int) $vinculo['prod_valor'],
                        (int) $vinculo['prod_valor_costo'],
                        $usuario,
                        $linea['id_agile'],
                        $descripcionMp,
                    );

                    $vinculadas++;
                } else {
                    $this->detalleService->agregarLineaAgilePendiente(
                        $nota,
                        $linea['id_agile'],
                        $descripcionMp,
                        (int) $linea['cantidad'],
                    );

                    $pendientes++;
                    $mensajes[] = 'Pendiente de vincular: '.$linea['descripcion'];
                }

                $agregadas++;
            }

            if ($desde === 0 && $hasta >= $total && $agregadas === 0) {
                throw new RuntimeException('No se detectaron productos en el texto pegado.');
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
     * Vínculo en agilemaeprod o, si no existe, sugerencia por similitud (igual que en el análisis).
     *
     * @return ?array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}
     */
    private function resolverProductoParaImportar(string $idAgile, string $descripcion): ?array
    {
        $vinculo = $this->resolverVinculoExistente($idAgile);
        if ($vinculo !== null) {
            return $vinculo;
        }

        return $this->resolverSugerenciaSimilitud($descripcion);
    }

    /**
     * @return ?array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}
     */
    private function resolverVinculoExistente(string $idAgile): ?array
    {
        $vinculo = AgileMaeprod::query()->find(trim($idAgile));
        if (! $vinculo || trim((string) $vinculo->prod_item) === '') {
            return null;
        }

        $mae = Maeprod::query()->find($vinculo->prod_item);
        if (! $mae) {
            return null;
        }

        return $this->maeprodArray($mae);
    }

    /**
     * @return ?array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}
     */
    private function resolverSugerenciaSimilitud(string $descripcion): ?array
    {
        $resultados = $this->busqueda->buscar($descripcion, null, 1);
        $mae = $resultados->first();
        if (! $mae instanceof Maeprod) {
            return null;
        }

        return $this->maeprodArray($mae);
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
}

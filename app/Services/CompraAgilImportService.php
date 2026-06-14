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
        $parsed = $this->parser->parse($texto);
        $lineas = [];
        $vinculados = 0;
        $conSugerencia = 0;

        foreach ($parsed['lineas'] as $linea) {
            $vinculo = $this->resolverVinculoExistente($linea['id_agile']);
            $sugerencia = $vinculo ? null : $this->resolverSugerenciaSimilitud($linea['descripcion']);
            $producto = $vinculo ?? $sugerencia;
            $estado = $vinculo ? 'vinculado' : 'pendiente';

            if ($vinculo) {
                $vinculados++;
            }
            if (! $vinculo && $sugerencia) {
                $conSugerencia++;
            }

            $lineas[] = [
                'id_agile' => $linea['id_agile'],
                'descripcion' => $linea['descripcion'],
                'cantidad' => $linea['cantidad'],
                'categoria' => $linea['categoria'],
                'producto' => $producto,
                'estado' => $estado,
                'es_sugerencia' => $vinculo === null && $sugerencia !== null,
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
            'resumen' => [
                'total' => count($lineas),
                'vinculados' => $vinculados,
                'pendientes' => count($lineas) - $vinculados,
                'con_sugerencia' => $conSugerencia,
            ],
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
                $this->agileMaeprodService->registrarSiNoExiste($linea['id_agile'], $linea['descripcion']);

                $vinculo = $this->resolverVinculoExistente($linea['id_agile']);

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
                        $linea['descripcion'],
                    );

                    $vinculadas++;
                } else {
                    $this->detalleService->agregarLineaAgilePendiente(
                        $nota,
                        $linea['id_agile'],
                        $linea['descripcion'],
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

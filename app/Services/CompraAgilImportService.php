<?php

namespace App\Services;

use App\Models\AgileMaeprod;
use App\Models\Maeprod;
use App\Models\Nota;
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
     *     estado: string
     *   }>,
     *   resumen: array{total: int, con_producto: int, sin_producto: int}
     * }
     */
    public function preview(string $texto): array
    {
        $parsed = $this->parser->parse($texto);
        $lineas = [];
        $conProducto = 0;

        foreach ($parsed['lineas'] as $linea) {
            $producto = $this->resolverProducto($linea['id_agile'], $linea['descripcion']);
            $estado = $producto ? 'ok' : 'sin_match';
            if ($producto) {
                $conProducto++;
            }

            $lineas[] = [
                'id_agile' => $linea['id_agile'],
                'descripcion' => $linea['descripcion'],
                'cantidad' => $linea['cantidad'],
                'categoria' => $linea['categoria'],
                'producto' => $producto,
                'estado' => $estado,
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
                'con_producto' => $conProducto,
                'sin_producto' => count($lineas) - $conProducto,
            ],
        ];
    }

    /**
     * @return array{agregadas: int, omitidas: int, cabecera_actualizada: bool, mensajes: string[]}
     */
    public function aplicar(Nota $nota, string $texto, string $usuario): array
    {
        $preview = $this->preview($texto);
        $mensajes = [];

        if ($preview['cabecera']['codigo_cotizacion'] === '' && $preview['lineas'] === []) {
            throw new RuntimeException('No se detectó información de Compra Ágil en el texto pegado.');
        }

        $cabeceraActualizada = false;
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

        $agregadas = 0;
        $omitidas = 0;

        foreach ($preview['lineas'] as $linea) {
            if ($linea['producto'] === null) {
                $omitidas++;
                $mensajes[] = 'Sin producto en maestro: '.$linea['descripcion'];

                continue;
            }

            $p = $linea['producto'];
            $this->agileMaeprodService->registrarSiNoExiste($linea['id_agile'], $linea['descripcion']);
            $this->agileMaeprodService->vincularCodigoInterno($linea['id_agile'], $p['prod_item']);

            $this->detalleService->agregarLinea(
                $nota,
                $p['prod_item'],
                (int) $linea['cantidad'],
                (int) $p['prod_valor'],
                (int) $p['prod_valor_costo'],
                $usuario,
                $linea['id_agile'],
                $linea['descripcion'],
            );

            $agregadas++;
        }

        if ($agregadas === 0 && $omitidas > 0) {
            throw new RuntimeException('No se agregó ninguna línea: ningún producto del texto coincide con el maestro.');
        }

        return [
            'agregadas' => $agregadas,
            'omitidas' => $omitidas,
            'cabecera_actualizada' => $cabeceraActualizada,
            'mensajes' => $mensajes,
        ];
    }

    /**
     * @return ?array{prod_item: string, prod_nombre: string, prod_valor: int, prod_valor_costo: int}
     */
    private function resolverProducto(string $idAgile, string $descripcion): ?array
    {
        $vinculo = AgileMaeprod::query()->find(trim($idAgile));
        if ($vinculo && trim((string) $vinculo->prod_item) !== '') {
            $mae = Maeprod::query()->find($vinculo->prod_item);
            if ($mae) {
                return $this->maeprodArray($mae);
            }
        }

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

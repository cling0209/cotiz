<?php

namespace App\Services;

/**
 * Parsea texto copiado desde la página de Compra Ágil (Mercado Público).
 */
class CompraAgilTextoParserService
{
    /**
     * @return array{
     *   codigo_cotizacion: string,
     *   empresa: string,
     *   rutempresa: string,
     *   nombre: string,
     *   lineas: array<int, array{id_agile: string, descripcion: string, cantidad: int, categoria: string}>
     * }
     */
    public function parse(string $texto): array
    {
        $texto = trim($texto);
        if ($texto === '') {
            return $this->vacio();
        }

        $lineas = preg_split('/\r\n|\r|\n/', $texto) ?: [];
        $lineas = array_map(fn (string $l) => trim($l), $lineas);

        $codigo = $this->extraerCodigoCotizacion($texto);
        $rut = $this->extraerRut($texto);
        $empresa = $this->extraerEmpresa($lineas, $rut);
        $nombre = $this->extraerNombre($lineas);
        $productos = $this->extraerProductos($lineas);

        return [
            'codigo_cotizacion' => $codigo,
            'empresa' => $empresa,
            'rutempresa' => $rut,
            'nombre' => $nombre,
            'lineas' => $productos,
        ];
    }

    public function normalizarRut(string $rut): string
    {
        $rut = trim($rut);
        $rut = str_replace('.', '', $rut);
        $rut = preg_replace('/\s+/u', '', $rut) ?? '';

        return mb_substr($rut, 0, 10);
    }

    /**
     * @return array{codigo_cotizacion: string, empresa: string, rutempresa: string, nombre: string, lineas: array}
     */
    private function vacio(): array
    {
        return [
            'codigo_cotizacion' => '',
            'empresa' => '',
            'rutempresa' => '',
            'nombre' => '',
            'lineas' => [],
        ];
    }

    private function extraerCodigoCotizacion(string $texto): string
    {
        if (preg_match('/cotizaci[oó]n\s+(\d+-\d+-COT\d+)/iu', $texto, $m)) {
            return strtoupper(trim($m[1]));
        }

        if (preg_match('/\b(\d+-\d+-COT\d+)\b/i', $texto, $m)) {
            return strtoupper(trim($m[1]));
        }

        return '';
    }

    private function extraerRut(string $texto): string
    {
        if (preg_match('/\bRUT\s+([\d.]+-[\dkK])/iu', $texto, $m)) {
            return $this->normalizarRut($m[1]);
        }

        if (preg_match('/\b(\d{1,2}\.\d{3}\.\d{3}-[\dkK])\b/u', $texto, $m)) {
            return $this->normalizarRut($m[1]);
        }

        return '';
    }

    /**
     * @param  string[]  $lineas
     */
    private function extraerEmpresa(array $lineas, string $rutNormalizado): string
    {
        $rutIdx = null;
        foreach ($lineas as $i => $linea) {
            if ($linea === '') {
                continue;
            }
            if (preg_match('/\bRUT\b/iu', $linea) || ($rutNormalizado !== '' && str_contains($this->normalizarRut($linea), $rutNormalizado))) {
                $rutIdx = $i;
                break;
            }
        }

        if ($rutIdx === null) {
            return '';
        }

        for ($i = $rutIdx - 1; $i >= 0; $i--) {
            $l = $lineas[$i];
            if ($l === '' || $this->esLineaEtiquetaCabecera($l)) {
                continue;
            }
            if (preg_match('/\bID:\s*\d+/i', $l)) {
                continue;
            }
            if (mb_strlen($l) >= 4) {
                return mb_substr($l, 0, 100);
            }
        }

        return '';
    }

    /**
     * @param  string[]  $lineas
     */
    private function extraerNombre(array $lineas): string
    {
        $count = count($lineas);
        for ($i = 0; $i < $count; $i++) {
            if (! preg_match('/^Nombre$/iu', $lineas[$i])) {
                continue;
            }
            for ($j = $i + 1; $j < $count; $j++) {
                $l = $lineas[$j];
                if ($l === '' || $this->esLineaEtiquetaCabecera($l)) {
                    continue;
                }

                return mb_substr($l, 0, 500);
            }
        }

        return '';
    }

    /**
     * @param  string[]  $lineas
     * @return array<int, array{id_agile: string, descripcion: string, cantidad: int, categoria: string}>
     */
    private function extraerProductos(array $lineas): array
    {
        $out = [];
        $count = count($lineas);
        $i = 0;

        while ($i < $count) {
            $linea = $lineas[$i];
            if (! preg_match('/\bID:\s*(\d+)\b/i', $linea, $idMatch)) {
                $i++;

                continue;
            }

            $idAgile = trim($idMatch[1]);
            $categoria = trim(preg_replace('/\s*ID:\s*\d+\s*$/i', '', $linea) ?? $linea);

            $descripcion = '';
            $cantidad = 1;
            $cantidadMatch = [];
            $i++;

            while ($i < $count) {
                $l = $lineas[$i];
                if ($l === '') {
                    $i++;

                    continue;
                }
                if (preg_match('/\bID:\s*\d+\b/i', $l)) {
                    break;
                }
                if ($this->esLineaCantidad($l, $cantidadMatch)) {
                    $cantidad = max(1, (int) ($cantidadMatch[1] ?? 1));
                    $i++;
                    break;
                }
                if ($this->esLineaUiMp($l)) {
                    $i++;

                    continue;
                }
                if ($descripcion === '' && mb_strlen($l) >= 3) {
                    $descripcion = $l;
                }
                $i++;
            }

            if ($descripcion !== '') {
                $out[] = [
                    'id_agile' => $idAgile,
                    'descripcion' => $descripcion,
                    'cantidad' => $cantidad,
                    'categoria' => mb_substr($categoria, 0, 200),
                ];
            }
        }

        return $out;
    }

    private function esLineaEtiquetaCabecera(string $linea): bool
    {
        return (bool) preg_match(
            '/^(Nombre|Descripción|Descripcion|Dirección|Direccion|Plazo de entrega|Presupuesto|Fecha|Detalle|Participar|Valor unitario|Subtotal|Monto total|IVA|Productos solicitados)/iu',
            $linea,
        );
    }

    private function esLineaUiMp(string $linea): bool
    {
        return (bool) preg_match(
            '/^(Valor unitario|Subtotal|Buscar|Cantidad|Cerrar|Productos solicitados|Participar de la Compra)/iu',
            $linea,
        );
    }

    /**
     * @param  array<int, string>  $cantidadMatch
     */
    private function esLineaCantidad(string $linea, ?array &$cantidadMatch): bool
    {
        if (preg_match('/^(\d+)\s*(Litro|litros?|Unidad|unidades?|Pack|packs?|Metro|metros?|Kilo|kilos?|Caja|cajas?|Par|pares?|Rollo|rollos?)\b/iu', $linea, $m)) {
            $cantidadMatch = $m;

            return true;
        }

        if (preg_match('/^(\d+)\s*$/u', $linea, $m)) {
            $cantidadMatch = $m;

            return true;
        }

        $cantidadMatch = null;

        return false;
    }
}

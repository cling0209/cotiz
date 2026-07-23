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
     *   region: ?int,
     *   nombre_region: string,
     *   comuna: string,
     *   direccion_entrega: string,
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
        $geo = $this->extraerGeo($texto, $lineas);

        return [
            'codigo_cotizacion' => $codigo,
            'empresa' => $empresa,
            'rutempresa' => $rut,
            'nombre' => $nombre,
            'region' => $geo['region'],
            'nombre_region' => $geo['nombre_region'],
            'comuna' => $geo['comuna'],
            'direccion_entrega' => $geo['direccion_entrega'],
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
     * Si el RUT viene sin guión/DV (típico en notas.rutempresa), calcula el dígito verificador.
     * Si ya trae DV inválido, lo corrige. Si el cuerpo no es usable, deja el normalizado.
     */
    public function completarRutConDv(string $rut): string
    {
        $rut = $this->normalizarRut($rut);
        if ($rut === '') {
            return '';
        }

        if (str_contains($rut, '-')) {
            [$body, $dv] = explode('-', $rut, 2);
            $cuerpo = preg_replace('/[^0-9]/', '', $body) ?? '';
            if (strlen($cuerpo) < 7 || strlen($cuerpo) > 8) {
                return $rut;
            }

            return $cuerpo.'-'.$this->calcularDvRut($cuerpo);
        }

        $cuerpo = preg_replace('/[^0-9]/', '', $rut) ?? '';
        if (strlen($cuerpo) < 7 || strlen($cuerpo) > 8) {
            return $rut;
        }

        return $cuerpo.'-'.$this->calcularDvRut($cuerpo);
    }

    /** Módulo 11 chileno (0–9 / K). */
    public function calcularDvRut(string $cuerpoNumerico): string
    {
        $cuerpo = preg_replace('/[^0-9]/', '', $cuerpoNumerico) ?? '';
        $suma = 0;
        $multiplo = 2;
        for ($i = strlen($cuerpo) - 1; $i >= 0; $i--) {
            $suma += ((int) $cuerpo[$i]) * $multiplo;
            $multiplo = $multiplo === 7 ? 2 : $multiplo + 1;
        }
        $dv = 11 - ($suma % 11);
        if ($dv === 11) {
            return '0';
        }
        if ($dv === 10) {
            return 'K';
        }

        return (string) $dv;
    }

    /**
     * @return array{
     *   codigo_cotizacion: string,
     *   empresa: string,
     *   rutempresa: string,
     *   nombre: string,
     *   region: ?int,
     *   nombre_region: string,
     *   comuna: string,
     *   direccion_entrega: string,
     *   lineas: array
     * }
     */
    private function vacio(): array
    {
        return [
            'codigo_cotizacion' => '',
            'empresa' => '',
            'rutempresa' => '',
            'nombre' => '',
            'region' => null,
            'nombre_region' => '',
            'comuna' => '',
            'direccion_entrega' => '',
            'lineas' => [],
        ];
    }

    /**
     * @param  string[]  $lineas
     * @return array{region: ?int, nombre_region: string, comuna: string, direccion_entrega: string}
     */
    private function extraerGeo(string $texto, array $lineas): array
    {
        $direccion = $this->extraerCampoEtiquetado($lineas, [
            'Dirección', 'Direccion', 'Lugar de entrega', 'Lugar de Entrega',
        ]);
        $comuna = $this->extraerCampoEtiquetado($lineas, ['Comuna']);
        $nombreRegion = $this->extraerCampoEtiquetado($lineas, ['Región', 'Region']);

        if ($nombreRegion === '' && preg_match('/Regi[oó]n\s+Metropolitana/iu', $texto)) {
            $nombreRegion = 'Metropolitana';
        }

        $region = null;
        if ($nombreRegion !== '') {
            $region = $this->codigoRegionDesdeNombre($nombreRegion);
        }

        return [
            'region' => $region,
            'nombre_region' => mb_substr($nombreRegion, 0, 100),
            'comuna' => mb_substr($comuna, 0, 120),
            'direccion_entrega' => mb_substr($direccion, 0, 255),
        ];
    }

    /**
     * @param  string[]  $lineas
     * @param  list<string>  $etiquetas
     */
    private function extraerCampoEtiquetado(array $lineas, array $etiquetas): string
    {
        foreach ($lineas as $i => $linea) {
            if ($linea === '') {
                continue;
            }
            foreach ($etiquetas as $etiqueta) {
                $pat = '/^'.preg_quote($etiqueta, '/').'\s*[:\-]?\s*(.*)$/iu';
                if (preg_match($pat, $linea, $m)) {
                    $valor = trim((string) ($m[1] ?? ''));
                    if ($valor !== '') {
                        return $valor;
                    }
                    for ($j = $i + 1; $j < count($lineas); $j++) {
                        $sig = trim($lineas[$j]);
                        if ($sig === '' || $this->esLineaEtiquetaCabecera($sig)) {
                            continue;
                        }

                        return $sig;
                    }
                }
            }
        }

        return '';
    }

    private function codigoRegionDesdeNombre(string $nombre): ?int
    {
        $norm = mb_strtolower(trim($nombre), 'UTF-8');
        $norm = strtr($norm, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
        ]);

        if (str_contains($norm, 'metropolitana')) {
            return CompraAgilRegionScope::REGION_METROPOLITANA;
        }

        foreach (CompraAgilRegionScope::catalogoRegiones() as $codigo => $label) {
            $labelNorm = mb_strtolower($label, 'UTF-8');
            $labelNorm = strtr($labelNorm, [
                'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
            ]);
            if ($labelNorm !== '' && (str_contains($norm, $labelNorm) || str_contains($labelNorm, $norm))) {
                return (int) $codigo;
            }
        }

        return null;
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
            if (preg_match('/ID:\s*\d+/iu', $l)) {
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
            if (! $this->extraerIdAgileDeLinea($linea, $idMatch)) {
                $i++;

                continue;
            }

            $idAgile = trim($idMatch[1]);
            $categoria = trim(preg_replace('/ID:\s*\d+.*$/iu', '', $linea) ?? '');
            $descripcionEnLinea = '';
            if (preg_match('/ID:\s*\d+\s+(.+)/iu', $linea, $descMatch)) {
                $descripcionEnLinea = trim($descMatch[1]);
            }

            $descripcion = $descripcionEnLinea;
            $cantidad = 1;
            $cantidadMatch = [];
            $siguienteId = null;
            $i++;

            while ($i < $count) {
                $l = $lineas[$i];
                if ($l === '' || $this->esLineaPrecioMp($l)) {
                    $i++;

                    continue;
                }
                if ($this->extraerIdAgileDeLinea($l, $siguienteId)) {
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

            if ($descripcion === '') {
                $descripcion = $categoria;
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

    /**
     * MP suele pegar "CategoríaID: 12345" sin espacio; \bID: no coincide ahí.
     *
     * @param  array<int, string>  $match
     */
    private function extraerIdAgileDeLinea(string $linea, ?array &$match): bool
    {
        if (preg_match('/ID:\s*(\d+)/iu', $linea, $m)) {
            $match = $m;

            return true;
        }

        $match = null;

        return false;
    }

    private function esLineaPrecioMp(string $linea): bool
    {
        $t = trim($linea);

        if ($t === '$') {
            return true;
        }

        if (preg_match('/^\$\s*[\d.,]+$/u', $t)) {
            return true;
        }

        return (bool) preg_match('/^[\d]{1,3}(\.[\d]{3})+$/u', $t);
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
        if (preg_match('/^(\d+)\s*(Litro|litros?|Unidad|unidades?|Pack|packs?|Metro|metros?|Kilo|kilos?|Caja|cajas?|Par|pares?|Rollo|rollos?|Bolsa|bolsas?)\b/iu', $linea, $m)) {
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

<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cliente Mistral OCR (tablas HTML) para importar listados de materiales.
 */
class PdfMistralOcrService
{
    public function estaDisponible(): bool
    {
        if (! filter_var($this->config('mistral_ocr.enabled', true), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        return $this->apiKey() !== '';
    }

    /**
     * @return array<int, array{pagina: int, filas: array<int, array<int, string>>, items: array<int, array{cantidad: int, descripcion: string}>}>
     */
    public function extraerGrillaTabla(
        string $pdfPath,
        string $nombreArchivo = '',
        string $columnaCantidad = '',
        string $columnaProducto = '',
    ): array {
        if (! is_readable($pdfPath)) {
            throw new RuntimeException('No se pudo leer el PDF para Mistral OCR.');
        }

        $key = $this->apiKey();
        if ($key === '') {
            throw new RuntimeException('Mistral OCR no configurado (COTIZ_MISTRAL_API_KEY).');
        }

        $pdfBytes = (string) file_get_contents($pdfPath);
        if ($pdfBytes === '') {
            throw new RuntimeException('PDF vacío para Mistral OCR.');
        }

        $timeout = max(30, (int) $this->config('mistral_ocr.timeout', 180));
        $model = trim((string) $this->config('mistral_ocr.model', 'mistral-ocr-latest')) ?: 'mistral-ocr-latest';
        $endpoint = rtrim((string) $this->config('mistral_ocr.endpoint', 'https://api.mistral.ai/v1/ocr'), '/');

        $response = Http::timeout($timeout)
            ->connectTimeout(20)
            ->withToken($key)
            ->acceptJson()
            ->asJson()
            ->post($endpoint, [
                'model' => $model,
                'document' => [
                    'type' => 'document_url',
                    'document_url' => 'data:application/pdf;base64,'.base64_encode($pdfBytes),
                ],
                'table_format' => 'html',
            ]);

        if (! $response->successful()) {
            Log::warning('Mistral OCR: respuesta HTTP no exitosa', [
                'status' => $response->status(),
                'archivo' => $nombreArchivo,
                'body' => mb_substr($response->body(), 0, 400),
            ]);
            throw new RuntimeException('Mistral OCR no pudo procesar el PDF (HTTP '.$response->status().').');
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('Mistral OCR devolvió un JSON inválido.');
        }

        return $this->paginasDesdeRespuesta(
            $json,
            trim($columnaCantidad),
            trim($columnaProducto),
        );
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<int, array{pagina: int, filas: array<int, array<int, string>>, items: array<int, array{cantidad: int, descripcion: string}>}>
     */
    public function paginasDesdeRespuesta(array $json, string $columnaCantidad, string $columnaProducto): array
    {
        $paginas = [];
        $pages = $json['pages'] ?? [];
        if (! is_array($pages)) {
            return [];
        }

        // Índices de columna persistentes entre páginas (tabla que continúa sin encabezado).
        $idxC = null;
        $idxP = null;

        foreach ($pages as $page) {
            if (! is_array($page)) {
                continue;
            }
            $index = (int) ($page['index'] ?? 0);
            $filas = [];
            $items = [];
            foreach ($page['tables'] ?? [] as $table) {
                $html = is_array($table) ? (string) ($table['content'] ?? '') : (string) $table;
                $rows = $this->filasDesdeHtmlTabla($html);
                if ($rows === []) {
                    continue;
                }
                $mapped = $this->itemsDesdeFilas(
                    $rows,
                    $columnaCantidad,
                    $columnaProducto,
                    $idxC,
                    $idxP,
                );
                if ($mapped === []) {
                    continue;
                }
                $filas = array_merge($filas, $rows);
                $items = array_merge($items, $mapped);
            }
            if ($filas === []) {
                continue;
            }
            $paginas[] = [
                'pagina' => $index + 1,
                'filas' => $filas,
                'items' => $items,
            ];
        }

        return $paginas;
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function filasDesdeHtmlTabla(string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];
        }

        $dom = new DOMDocument;
        $wrapped = '<!DOCTYPE html><html><body>'.$html.'</body></html>';
        if (! @$dom->loadHTML('<?xml encoding="UTF-8">'.$wrapped)) {
            return [];
        }

        $filas = [];
        foreach ($dom->getElementsByTagName('tr') as $tr) {
            if (! $tr instanceof DOMElement) {
                continue;
            }
            $celdas = [];
            foreach ($tr->childNodes as $child) {
                if (! $child instanceof DOMElement) {
                    continue;
                }
                $tag = strtolower($child->tagName);
                if ($tag !== 'td' && $tag !== 'th') {
                    continue;
                }
                $texto = html_entity_decode((string) $child->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? '');
                $celdas[] = $texto;
            }
            if ($celdas !== [] && implode('', $celdas) !== '') {
                $filas[] = $celdas;
            }
        }

        return $filas;
    }

    /**
     * Extrae ítems de filas. Si $idxC/$idxP vienen de páginas anteriores, continúa
     * la tabla sin encabezado. Si la tabla trae un encabezado distinto al pedido,
     * no toma esas filas (el mapeo previo se conserva para páginas siguientes).
     *
     * @param  array<int, array<int, string>>  $filas
     * @param  int|null  $idxC
     * @param  int|null  $idxP
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    public function itemsDesdeFilas(
        array $filas,
        string $columnaCantidad,
        string $columnaProducto,
        ?int &$idxC = null,
        ?int &$idxP = null,
    ): array {
        if ($filas === []) {
            return [];
        }

        // ANEXO ENAMI / oferta económica: 1 o 2 tablas lado a lado con
        // N° ítem | Descripción | Unidad | Precio (sin columna cantidad real).
        $oferta = $this->itemsDesdeFilasOfertaPrecio($filas, $columnaProducto);
        if ($oferta !== null) {
            // Evita que la continuación sticky tome N° ítem como "cantidad".
            $idxC = null;
            $idxP = null;

            return $oferta;
        }

        $inicio = 0;
        $headerLocal = null;
        foreach ($filas as $i => $fila) {
            if ($i > 3) {
                break;
            }
            $headerLocal = $this->resolverEncabezadoUsuario($fila, $columnaCantidad, $columnaProducto);
            if ($headerLocal !== null) {
                $idxC = $headerLocal['cantidad'];
                $idxP = $headerLocal['producto'];
                $inicio = $i + 1;
                break;
            }
        }

        if ($headerLocal === null) {
            if ($idxC === null || $idxP === null) {
                return [];
            }
            // Tabla con otro encabezado / logística: no mezclar; conservar índices para continuación.
            if (
                $this->pareceFilaEncabezadoAjeno($filas[0], $columnaCantidad, $columnaProducto)
                || $this->esFilaSeccionOLogistica(implode(' ', $filas[0]))
                || $this->esFilaPieTotales(implode(' ', $filas[0]))
            ) {
                return [];
            }
        }

        $items = [];
        for ($i = $inicio; $i < count($filas); $i++) {
            $fila = $filas[$i];
            // Encabezado repetido a mitad de tabla (misma o distinta).
            $headerMid = $this->resolverEncabezadoUsuario($fila, $columnaCantidad, $columnaProducto);
            if ($headerMid !== null) {
                $idxC = $headerMid['cantidad'];
                $idxP = $headerMid['producto'];

                continue;
            }
            $textoFilaPre = trim(implode(' ', $fila));
            if ($this->pareceFilaEncabezadoAjeno($fila, $columnaCantidad, $columnaProducto)) {
                // Solo cortar ante logística/pies; no abandonar el resto por un falso encabezado OCR.
                if (
                    $this->esFilaSeccionOLogistica($textoFilaPre)
                    || $this->esFilaPieTotales($textoFilaPre)
                ) {
                    break;
                }

                continue;
            }
            if ($idxC === null || $idxP === null) {
                continue;
            }
            while (count($fila) <= max($idxC, $idxP)) {
                $fila[] = '';
            }
            $cantidad = $this->parseCantidad($fila[$idxC] ?? '');
            $descripcion = trim((string) ($fila[$idxP] ?? ''));
            $textoFila = $textoFilaPre !== '' ? $textoFilaPre : trim(implode(' ', $fila));
            if (
                $this->esFilaPieTotales($descripcion)
                || $this->esFilaPieTotales($textoFila)
                || $this->esFilaSeccionOLogistica($descripcion)
                || $this->esFilaSeccionOLogistica($textoFila)
            ) {
                break;
            }
            if ($cantidad !== null && mb_strlen($descripcion) >= 2) {
                $items[] = [
                    'cantidad' => $cantidad,
                    'descripcion' => $descripcion,
                ];
            }
        }

        return $items;
    }

    /**
     * Oferta económica dual (ENAMI): una o dos columnas con el mismo encabezado
     * Descripción/Unidad/Precio. Cantidad fija en 1 (N° ítem no es cantidad).
     *
     * @param  array<int, array<int, string>>  $filas
     * @return array<int, array{cantidad: int, descripcion: string}>|null null = no es este formato
     */
    public function itemsDesdeFilasOfertaPrecio(array $filas, string $columnaProducto = ''): ?array
    {
        $inicio = null;
        $indicesDesc = [];
        foreach ($filas as $i => $fila) {
            if ($i > 4) {
                break;
            }
            $indices = $this->indicesDescripcionOfertaEnFila($fila, $columnaProducto);
            if ($indices === [] || ! $this->filaEsEncabezadoOfertaPrecio($fila)) {
                continue;
            }
            $inicio = $i + 1;
            $indicesDesc = $indices;
            break;
        }

        if ($inicio === null || $indicesDesc === []) {
            return null;
        }

        $items = [];
        for ($i = $inicio; $i < count($filas); $i++) {
            $fila = $filas[$i];
            if ($this->filaEsEncabezadoOfertaPrecio($fila)) {
                $indicesDesc = $this->indicesDescripcionOfertaEnFila($fila, $columnaProducto) ?: $indicesDesc;

                continue;
            }
            $textoFila = trim(implode(' ', $fila));
            if ($this->esFilaPieTotales($textoFila) || $this->esFilaSeccionOLogistica($textoFila)) {
                break;
            }
            // Cuadro de distribución / logística con otro encabezado.
            if ($this->filaPareceEncabezadoDistintoDeOferta($fila)) {
                break;
            }

            foreach ($indicesDesc as $idxP) {
                while (count($fila) <= $idxP) {
                    $fila[] = '';
                }
                $descripcion = trim((string) ($fila[$idxP] ?? ''));
                if (mb_strlen($descripcion) < 3) {
                    continue;
                }
                if (
                    $this->esFilaPieTotales($descripcion)
                    || $this->esFilaSeccionOLogistica($descripcion)
                    || preg_match('/^(?:UNI|CJA|PQT|UND|UNIDAD|NOTAS)\b/iu', $this->normalizar($descripcion)) === 1
                ) {
                    continue;
                }
                $items[] = [
                    'cantidad' => 1,
                    'descripcion' => $descripcion,
                ];
            }
        }

        return $items === [] ? null : $items;
    }

    /**
     * @param  array<int, string>  $fila
     */
    public function filaEsEncabezadoOfertaPrecio(array $fila): bool
    {
        $tieneDesc = false;
        $tieneUnidad = false;
        $tienePrecio = false;
        $tieneItem = false;
        $descCount = 0;
        foreach ($fila as $celda) {
            $n = $this->normalizar((string) $celda);
            if ($n === '') {
                continue;
            }
            if (str_contains($n, 'DESCRIPCION') || (str_contains($n, 'PRODUCTO') && ! str_contains($n, 'PRECIO'))) {
                $tieneDesc = true;
                if (str_contains($n, 'DESCRIPCION')) {
                    $descCount++;
                }
            }
            if (preg_match('/\bUNIDAD(?:ES)?\b/u', $n) === 1) {
                $tieneUnidad = true;
            }
            if (str_contains($n, 'PRECIO') || str_contains($n, 'NETO UNITARIO')) {
                $tienePrecio = true;
            }
            if (preg_match('/\b(?:N\s*[°º.]?\s*)?ITEM\b/u', $n) === 1 || $n === 'N ITEM' || str_starts_with($n, 'N ITEM')) {
                $tieneItem = true;
            }
        }

        // Dos columnas Descripción lado a lado (ENAMI).
        if ($descCount >= 2) {
            return true;
        }

        if ($tieneDesc && $tieneUnidad && $tienePrecio) {
            return true;
        }

        // Catálogo con N° ítem + Descripción + Unidad (precio OCR incompleto).
        return $tieneDesc && $tieneUnidad && $tieneItem;
    }

    /**
     * @param  array<int, string>  $fila
     * @return list<int>
     */
    public function indicesDescripcionOfertaEnFila(array $fila, string $columnaProducto = ''): array
    {
        $indices = [];
        $producto = trim($columnaProducto);
        foreach ($fila as $i => $celda) {
            $n = $this->normalizar((string) $celda);
            if ($n === '') {
                continue;
            }
            $esDesc = str_contains($n, 'DESCRIPCION')
                || ($producto !== '' && $this->encabezadoCoincide((string) $celda, $producto));
            if (! $esDesc) {
                continue;
            }
            // Evitar "Precio Neto Unitario ($)" u otras celdas largas que no son descripción.
            if (str_contains($n, 'PRECIO') || str_contains($n, 'UNITARIO')) {
                continue;
            }
            $indices[] = (int) $i;
        }

        return array_values(array_unique($indices));
    }

    /**
     * @param  array<int, string>  $fila
     */
    public function filaPareceEncabezadoDistintoDeOferta(array $fila): bool
    {
        if ($this->filaEsEncabezadoOfertaPrecio($fila)) {
            return false;
        }

        $texto = trim(implode(' ', $fila));
        $n = $this->normalizar($texto);
        if ($n === '') {
            return false;
        }

        if (
            str_contains($n, 'DISTRIBUCION')
            || str_contains($n, 'GRATUITO')
            || str_contains($n, 'CONDICIONES')
            || str_contains($n, 'PLAZO ENTREGA')
            || str_contains($n, 'LUGAR DE ENTREGA')
        ) {
            $celdas = array_values(array_filter(
                array_map(static fn ($c): string => trim((string) $c), $fila),
                static fn (string $c): bool => $c !== '',
            ));

            return count($celdas) >= 2 && preg_match('/^\d{1,4}$/u', $celdas[0]) !== 1;
        }

        return false;
    }

    /**
     * Pie de cotización / totales: no es producto.
     */
    public function esFilaPieTotales(string $texto): bool
    {
        $n = $this->normalizar($texto);
        if ($n === '') {
            return false;
        }

        foreach ([
            'CONDICIONES DE VENTA',
            'SUBTOTAL NETO',
            'SUB TOTAL NETO',
            'SUB TOTAL',
            'SUBTOTAL',
            'TERMINOS DE PAGO',
            'TIEMPO DE ENTREGA',
            'PRECIO DE VENTA',
        ] as $marcador) {
            if (str_starts_with($n, $marcador) || str_contains($n, $marcador)) {
                return true;
            }
        }

        if (preg_match('/^(?:RHEIN|IVA\s*\d|TOTAL)\b/u', $n) === 1) {
            return true;
        }

        return preg_match('/\bRHEIN\b.*\b(?:SUBTOTAL|IVA|TOTAL)\b/u', $n) === 1;
    }

    /**
     * Sección o tabla ajena (lugar de entrega, horarios, contacto) — no es ítem de productos.
     */
    public function esFilaSeccionOLogistica(string $texto): bool
    {
        $n = $this->normalizar($texto);
        if ($n === '') {
            return false;
        }

        if (preg_match('/^(?:\d+[\.\-)\s]+)?(?:LUGAR DE ENTREGA|CONDICIONES(?:\s+DE\s+ENTREGA)?|FORMA DE PAGO|PLAZO DE ENTREGA|OBSERVACIONES|NOTAS)\b/u', $n) === 1) {
            return true;
        }

        if (str_contains($n, 'LUGAR DE ENTREGA')) {
            return true;
        }

        // Horario laboral típico de tabla de recepción.
        if (
            preg_match('/\bLUNES\s+A\s+VIERNES\b/u', $n) === 1
            && preg_match('/\b\d{1,2}\s*:\s*\d{2}\b/u', $texto) === 1
        ) {
            return true;
        }

        if (
            preg_match('/\b(?:CESFAM|CONSULTORIO|HOSPITAL|MUNICIPALIDAD|DIRECCION DE ENTREGA)\b/u', $n) === 1
            && preg_match('/\b(?:HORAS|CONTACTAR|RECEPCION|COORDINAR|SECRETARIA)\b/u', $n) === 1
        ) {
            return true;
        }

        return preg_match('/\b(?:PERSONAS A COORDINAR|COORDINAR RECEPCION|RECEPCION DE PRODUCTOS)\b/u', $n) === 1;
    }

    /**
     * @param  array<int, string>  $fila
     * @return array{cantidad: int, producto: int}|null
     */
    public function resolverEncabezadoUsuario(array $fila, string $columnaCantidad, string $columnaProducto): ?array
    {
        $foundC = $foundP = null;
        foreach ($fila as $i => $celda) {
            if ($this->encabezadoCoincide($celda, $columnaCantidad)) {
                $foundC = $i;
            }
            if ($this->encabezadoCoincide($celda, $columnaProducto)) {
                $foundP = $i;
            }
        }
        if ($foundC === null || $foundP === null || $foundC === $foundP) {
            return null;
        }

        return ['cantidad' => $foundC, 'producto' => $foundP];
    }

    /**
     * Fila que parece encabezado de otra tabla (no la pedida por el usuario).
     *
     * @param  array<int, string>  $fila
     */
    public function pareceFilaEncabezadoAjeno(array $fila, string $columnaCantidad, string $columnaProducto): bool
    {
        if ($this->resolverEncabezadoUsuario($fila, $columnaCantidad, $columnaProducto) !== null) {
            return false;
        }

        $textoFila = trim(implode(' ', $fila));
        if ($this->esFilaSeccionOLogistica($textoFila) || $this->esFilaPieTotales($textoFila)) {
            return true;
        }

        $celdas = array_values(array_filter(
            array_map(static fn ($c): string => trim((string) $c), $fila),
            static fn (string $c): bool => $c !== '',
        ));
        if (count($celdas) < 2) {
            return false;
        }

        // Continuación típica: empieza con número de línea / cantidad.
        if (preg_match('/^\d{1,5}(?:\s*unidades?)?$/iu', $celdas[0]) === 1) {
            return false;
        }

        $etiquetas = 0;
        foreach ($celdas as $celda) {
            $n = $this->normalizar($celda);
            if ($n === '') {
                continue;
            }
            if (preg_match('/^\d{1,5}(?:\s*UNIDADES?)?$/u', $n) === 1) {
                continue;
            }
            $etiquetas++;
        }

        return $etiquetas >= 2;
    }

    public function encabezadoCoincide(string $celda, string $nombre): bool
    {
        $n = $this->normalizar($celda);
        $p = $this->normalizar($nombre);
        if ($n === '' || $p === '') {
            return false;
        }
        if ($n === $p || str_contains($n, $p) || str_contains($p, $n)) {
            return true;
        }

        $tokens = array_values(array_filter(
            explode(' ', $p),
            static fn (string $t): bool => mb_strlen($t) >= 4 && ! in_array($t, ['POR', 'PARA', 'DESDE', 'HASTA'], true),
        ));
        if ($tokens === []) {
            return false;
        }

        foreach ($tokens as $token) {
            if (! str_contains($n, $token)) {
                return false;
            }
        }

        return true;
    }

    public function parseCantidad(string $texto): ?int
    {
        $texto = trim($texto);
        if ($texto === '') {
            return null;
        }

        // No tomar dígitos de horarios (08:00 → 8).
        $sinHoras = trim((string) (preg_replace('/\b\d{1,2}:\d{2}(?::\d{2})?\b/u', ' ', $texto) ?? $texto));
        if ($sinHoras === '') {
            return null;
        }

        if (preg_match('/(\d{1,5})\s*(?:unidades?|und\.?|u\.?)\b/iu', $sinHoras, $m) === 1) {
            $valor = (int) $m[1];

            return $valor >= 1 && $valor <= 99999 ? $valor : null;
        }

        if (preg_match('/^\s*(\d{1,5})\s*$/u', $sinHoras, $m) === 1) {
            $valor = (int) $m[1];

            return $valor >= 1 && $valor <= 99999 ? $valor : null;
        }

        if (preg_match('/(\d{1,5})/', str_replace(['.', ','], '', $sinHoras), $m) !== 1) {
            return null;
        }
        $valor = (int) $m[1];

        return $valor >= 1 && $valor <= 99999 ? $valor : null;
    }

    public function normalizar(string $texto): string
    {
        $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $normalizado = \Normalizer::normalize($texto, \Normalizer::FORM_KD);
            if (is_string($normalizado) && $normalizado !== '') {
                $texto = $normalizado;
            }
            $texto = preg_replace('/\p{Mn}+/u', '', $texto) ?? $texto;
        }
        $texto = strtr($texto, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
            'á' => 'A', 'é' => 'E', 'í' => 'I', 'ó' => 'O', 'ú' => 'U', 'ü' => 'U', 'ñ' => 'N',
        ]);
        $texto = mb_strtoupper($texto);
        $texto = preg_replace('/[^A-Z0-9 ]/u', ' ', $texto) ?? $texto;

        return trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);
    }

    private function apiKey(): string
    {
        return trim((string) $this->config('mistral_ocr.api_key', ''), " \t\n\r\0\x0B\"'");
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return config('cotiz.'.$key, $default);
    }
}

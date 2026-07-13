<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Smalot\PdfParser\Parser;
use ZipArchive;

class ListadoMaterialesPdfParserService
{
    private const FORMATO_LISTADO = 'listado_cantidad';

    private const FORMATO_DETALLE = 'detalle_unidades';

    private const FORMATO_LICITACION = 'licitacion_pedido';

    private const FORMATO_BASES = 'bases_linea';

    /**
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    public function parseUploadedFile(UploadedFile $file): array
    {
        $path = $file->getRealPath() ?: $file->getPathname();
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: pathinfo($path, PATHINFO_EXTENSION)));

        if ($extension === 'doc') {
            throw new RuntimeException(
                'El formato .doc antiguo no está soportado. Guarde el archivo como .docx o PDF e intente de nuevo.',
            );
        }

        if ($extension === 'docx') {
            $desdeTablas = $this->parseDocxTablas($path);
            if ($desdeTablas !== []) {
                return $desdeTablas;
            }

            $texto = $this->extraerTextoDocx($path);

            return $this->parseTexto($texto);
        }

        $texto = $this->extraerTextoPdf($path);

        return $this->parseTexto($texto);
    }

    /**
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    public function parseTexto(string $texto): array
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $formato = $this->detectarFormato($texto);

        return match ($formato) {
            self::FORMATO_DETALLE => $this->parseDetalleUnidades($texto),
            self::FORMATO_LICITACION => $this->parseLicitacionPedido($texto),
            self::FORMATO_BASES => $this->parseBasesLinea($texto),
            default => $this->parseListadoCantidad($texto),
        };
    }

    public function detectarFormato(string $texto): string
    {
        $upper = mb_strtoupper($texto);

        if (str_contains($upper, 'PEDIDO ESTABLECIMIENTO') && str_contains($upper, 'CANTIDAD')) {
            return self::FORMATO_LICITACION;
        }

        if (str_contains($upper, 'DETALLE PRODUCTO') && str_contains($upper, 'UNIDADES')) {
            return self::FORMATO_DETALLE;
        }

        if (
            str_contains($upper, 'LINEA DESCRIPCION')
            || (str_contains($upper, 'UNIDADES*') && str_contains($upper, 'MONTO TOTAL'))
        ) {
            return self::FORMATO_BASES;
        }

        return self::FORMATO_LISTADO;
    }

    /**
     * Formato canónico: "Cantidad NOMBRE DEL PRODUCTO" / "40 ACUARELAS..."
     *
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function parseListadoCantidad(string $texto): array
    {
        $texto = preg_replace('/[ \t]+/u', ' ', $texto) ?? $texto;
        $lineas = array_values(array_filter(
            array_map(static fn (string $linea) => trim($linea), explode("\n", $texto)),
            static fn (string $linea) => $linea !== '',
        ));

        $resultado = [];
        $indiceActual = null;

        foreach ($lineas as $linea) {
            if ($this->esEncabezadoListado($linea)) {
                continue;
            }

            if ($this->esRuidoListado($linea)) {
                continue;
            }

            if (preg_match('/^(\d{1,6})\s+(.+)$/u', $linea, $coincidencia) === 1) {
                $resultado[] = [
                    'cantidad' => max(1, (int) $coincidencia[1]),
                    'descripcion' => trim($coincidencia[2]),
                ];
                $indiceActual = count($resultado) - 1;

                continue;
            }

            if ($indiceActual !== null) {
                $resultado[$indiceActual]['descripcion'] = trim(
                    $resultado[$indiceActual]['descripcion'].' '.$linea,
                );
            }
        }

        return $resultado;
    }

    /**
     * Formato: "DETALLE PRODUCTO UNIDADES FORMATO" / "CLIP 28MM 50 CAJAS"
     *
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function parseDetalleUnidades(string $texto): array
    {
        $unidades = 'CAJAS|UNIDADES|HOJAS|RESMA|ROLLOS?|PACKS?|SOBRES?|FRASCOS?|CAJA|UNIDAD';
        $patron = '/^(.+?)\s+(\d+)\s+('.$unidades.')\s*$/iu';
        $resultado = [];

        foreach (explode("\n", $texto) as $lineaCruda) {
            $linea = trim(preg_replace('/[ \t]+/u', ' ', $lineaCruda) ?? $lineaCruda);
            if ($linea === '') {
                continue;
            }

            $upper = mb_strtoupper($linea);
            if (str_contains($upper, 'DETALLE PRODUCTO')) {
                continue;
            }

            if (preg_match($patron, $linea, $m) !== 1) {
                continue;
            }

            $descripcion = trim($m[1]);
            if ($descripcion === '') {
                continue;
            }

            $resultado[] = [
                'cantidad' => max(1, (int) $m[2]),
                'descripcion' => $descripcion,
            ];
        }

        return $resultado;
    }

    /**
     * Formato: "PEDIDO ESTABLECIMIENTO PRODUCTO CANTIDAD"
     * El número inicial es el pedido; la cantidad va al final del bloque.
     *
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function parseLicitacionPedido(string $texto): array
    {
        $lineas = [];
        foreach (explode("\n", $texto) as $lineaCruda) {
            $linea = trim(preg_replace('/[ \t]+/u', ' ', $lineaCruda) ?? $lineaCruda);
            if ($linea !== '') {
                $lineas[] = $linea;
            }
        }

        $resultado = [];
        $buffer = [];

        $flush = function () use (&$buffer, &$resultado): void {
            if ($buffer === []) {
                return;
            }

            $textoBloque = trim(implode(' ', $buffer));
            $buffer = [];
            $upper = mb_strtoupper($textoBloque);

            if (
                str_contains($upper, 'PEDIDO ESTABLECIMIENTO')
                || str_starts_with($upper, 'MONTO ESTIMADO')
            ) {
                return;
            }

            if (preg_match('/^(?:\d{4,6}\s+)?(.+?)\s+(\d+)\s*$/u', $textoBloque, $m) !== 1) {
                return;
            }

            $descripcion = trim($m[1]);
            $cantidad = (int) $m[2];

            if ($descripcion === '' || mb_strlen($descripcion) < 3 || $cantidad > 100000) {
                return;
            }

            // Quitar código de pedido si quedó pegado al inicio por rarezas de extracción.
            $descripcion = preg_replace('/^\d{4,6}\s+/u', '', $descripcion) ?? $descripcion;
            $descripcion = trim($descripcion);
            if ($descripcion === '' || mb_strlen($descripcion) < 3) {
                return;
            }

            $resultado[] = [
                'cantidad' => max(1, $cantidad),
                'descripcion' => $descripcion,
            ];
        };

        foreach ($lineas as $linea) {
            $upper = mb_strtoupper($linea);

            if (str_contains($upper, 'PEDIDO ESTABLECIMIENTO')) {
                $flush();

                continue;
            }

            if (str_starts_with($upper, 'MONTO ESTIMADO')) {
                $flush();

                continue;
            }

            if (preg_match('/^\d{4,6}\b/u', $linea) === 1) {
                $flush();
                $buffer = [$linea];

                continue;
            }

            if (preg_match('/^\d+$/u', $linea) === 1 && $buffer !== []) {
                $buffer[] = $linea;
                $flush();

                continue;
            }

            if ($buffer !== []) {
                $buffer[] = $linea;
            } else {
                $buffer = [$linea];
            }
        }

        $flush();

        return $resultado;
    }

    /**
     * Formato bases/licitación: "LÍNEA / DESCRIPCIÓN / UNIDADES / MONTO"
     * Usa UNIDADES (referenciales) como cantidad.
     *
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function parseBasesLinea(string $texto): array
    {
        $catalogo = $this->extraerSeccionCatalogoBases($texto);
        $catalogo = $this->limpiarRuidoBases($catalogo);

        $raw = [];
        foreach (explode("\n", $catalogo) as $lineaCruda) {
            $linea = trim(preg_replace('/[ \t]+/u', ' ', $lineaCruda) ?? $lineaCruda);
            if ($linea !== '') {
                $raw[] = $linea;
            }
        }

        $resultado = [];
        $orphan = '';
        $buffer = '';
        $patronFin = '/^(\d{1,3})\s+(.+?)\s+(\d{1,3}(?:\.\d{3})+|\d+)\s+(\d{1,3}(?:\.\d{3})+)$/u';

        $tryFlush = function (string $text) use (&$resultado, $patronFin): bool {
            if (preg_match($patronFin, $text, $m) !== 1) {
                return false;
            }

            $descripcion = trim($m[2]);
            $unidades = str_replace('.', '', $m[3]);
            if ($descripcion === '' || ! ctype_digit($unidades)) {
                return false;
            }

            $resultado[] = [
                'cantidad' => max(1, (int) $unidades),
                'descripcion' => $descripcion,
            ];

            return true;
        };

        foreach ($raw as $linea) {
            if (preg_match('/^\d{1,3}$/u', $linea) === 1) {
                if ($buffer !== '' && ! $tryFlush($buffer)) {
                    $orphan = trim($orphan.' '.$buffer);
                }
                $buffer = $linea;

                continue;
            }

            if (preg_match('/^\d{1,3}\s+/u', $linea) === 1) {
                if ($buffer !== '' && ! $tryFlush($buffer)) {
                    $orphan = trim($orphan.' '.$buffer);
                }

                $text = $linea;
                if ($orphan !== '') {
                    if (preg_match('/^(\d{1,3})\s+(.*)$/u', $text, $m) === 1) {
                        $text = trim($m[1].' '.$orphan.' '.$m[2]);
                    }
                    $orphan = '';
                }

                $buffer = $text;
                if ($tryFlush($buffer)) {
                    $buffer = '';
                }

                continue;
            }

            if ($buffer !== '') {
                $buffer = trim($buffer.' '.$linea);
                if ($tryFlush($buffer)) {
                    $buffer = '';
                }
            } else {
                $orphan = trim($orphan.' '.$linea);
            }
        }

        if ($buffer !== '') {
            $tryFlush($buffer);
        }

        return $resultado;
    }

    private function extraerSeccionCatalogoBases(string $texto): string
    {
        $inicio = mb_stripos($texto, 'LINEA DESCRIPCION');
        if ($inicio === false) {
            $inicio = mb_stripos($texto, 'LÍNEA DESCRIPCION');
        }

        $fin = mb_stripos($texto, 'Los oferentes podrán postular');
        if ($fin === false) {
            $fin = mb_stripos($texto, 'Los oferentes podran postular');
        }

        if ($inicio !== false && $fin !== false && $fin > $inicio) {
            return mb_substr($texto, $inicio, $fin - $inicio);
        }

        if ($inicio !== false) {
            return mb_substr($texto, $inicio);
        }

        return $texto;
    }

    private function limpiarRuidoBases(string $catalogo): string
    {
        $catalogo = preg_replace('/P[aá]gina\s+\d+\s+de\s+\d+/iu', "\n", $catalogo) ?? $catalogo;
        $catalogo = preg_replace('/Corporaci[oó]n de\s+E[do]ucaci[oó]n y Salud/iu', "\n", $catalogo) ?? $catalogo;
        $catalogo = preg_replace('/\bLAS CONDES\b/u', "\n", $catalogo) ?? $catalogo;
        $catalogo = preg_replace('/\bMUNICIPALIDAD\b/u', "\n", $catalogo) ?? $catalogo;
        $catalogo = preg_replace('/LINEA DESCRIPCION REQUERIMIENTO/iu', "\n", $catalogo) ?? $catalogo;
        $catalogo = preg_replace(
            '/UNIDADES\*\s*POR\s*A[ÑN]O\s*Monto Total\s*\(\$\)\s*POR\s*A[ÑN]O/iu',
            "\n",
            $catalogo,
        ) ?? $catalogo;

        return $catalogo;
    }

    /**
     * Extrae filas de tablas Word con columnas Cantidad + Producto (p. ej. Compra Ágil).
     *
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    public function parseDocxTablas(string $path): array
    {
        $xml = $this->leerDocumentXmlDocx($path);
        $filas = $this->extraerFilasTablaDocx($xml);
        if ($filas === []) {
            return [];
        }

        $resultado = [];
        $idxCantidad = null;
        $idxProducto = null;

        foreach ($filas as $celdas) {
            $normalizadas = [];
            foreach ($celdas as $celda) {
                $normalizadas[] = $this->normalizarEncabezadoCelda($celda);
            }

            if ($idxCantidad === null) {
                $idxCantidad = $this->indiceColumna($normalizadas, ['CANTIDAD']);
                $idxProducto = $this->indiceColumna($normalizadas, ['PRODUCTO', 'NOMBRE DEL PRODUCTO', 'NOMBRE', 'DESCRIPCION', 'DESCRIPCIÓN']);
                if ($idxCantidad !== null && $idxProducto !== null) {
                    continue;
                }
                $idxCantidad = null;
                $idxProducto = null;

                continue;
            }

            $cantidadRaw = trim($celdas[$idxCantidad] ?? '');
            $descripcion = trim($celdas[$idxProducto] ?? '');
            if ($descripcion === '' || ! preg_match('/^\d{1,6}$/u', $cantidadRaw)) {
                continue;
            }

            $resultado[] = [
                'cantidad' => max(1, (int) $cantidadRaw),
                'descripcion' => $descripcion,
            ];
        }

        return $resultado;
    }

    private function extraerTextoPdf(string $path): string
    {
        if (! is_readable($path)) {
            throw new RuntimeException('No se pudo leer el archivo.');
        }

        try {
            $parser = new Parser;
            $pdf = $parser->parseFile($path);
            $texto = trim((string) $pdf->getText());
        } catch (\Throwable $e) {
            throw new RuntimeException('No se pudo extraer texto del PDF. Verifique que no sea un documento escaneado.', 0, $e);
        }

        if ($texto === '') {
            throw new RuntimeException(
                'El PDF no contiene texto legible (posible escaneo). Use un PDF/Word con texto nativo o un listado digitalizado, no una imagen.',
            );
        }

        return $texto;
    }

    private function extraerTextoDocx(string $path): string
    {
        $xml = $this->leerDocumentXmlDocx($path);
        $filas = $this->extraerFilasTablaDocx($xml);
        $lineas = [];

        foreach ($filas as $celdas) {
            $lineas[] = trim(implode(' ', array_filter($celdas, static fn (string $c) => $c !== '')));
        }

        $parrafos = $this->extraerParrafosDocx($xml);
        foreach ($parrafos as $parrafo) {
            $lineas[] = $parrafo;
        }

        $texto = trim(implode("\n", array_filter($lineas, static fn (string $l) => $l !== '')));
        if ($texto === '') {
            throw new RuntimeException('El archivo Word no contiene texto legible para importar.');
        }

        return $texto;
    }

    private function leerDocumentXmlDocx(string $path): string
    {
        if (! is_readable($path)) {
            throw new RuntimeException('No se pudo leer el archivo Word.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('No se pudo abrir el archivo Word (.docx).');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false || trim($xml) === '') {
            throw new RuntimeException('El archivo Word no tiene contenido legible (document.xml).');
        }

        return $xml;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function extraerFilasTablaDocx(string $xml): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $cargado = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $cargado) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $filas = [];
        foreach ($xpath->query('//w:tr') ?: [] as $tr) {
            if (! $tr instanceof DOMElement) {
                continue;
            }

            $celdas = [];
            foreach ($xpath->query('.//w:tc', $tr) ?: [] as $tc) {
                if (! $tc instanceof DOMElement) {
                    continue;
                }
                $celdas[] = $this->textoNodoDocx($tc);
            }

            if ($celdas !== []) {
                $filas[] = $celdas;
            }
        }

        return $filas;
    }

    /**
     * @return array<int, string>
     */
    private function extraerParrafosDocx(string $xml): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $cargado = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $cargado) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $parrafos = [];
        foreach ($xpath->query('//w:p[not(ancestor::w:tc)]') ?: [] as $p) {
            if (! $p instanceof DOMElement) {
                continue;
            }
            $texto = $this->textoNodoDocx($p);
            if ($texto !== '') {
                $parrafos[] = $texto;
            }
        }

        return $parrafos;
    }

    private function textoNodoDocx(DOMNode $nodo): string
    {
        $documento = $nodo->ownerDocument;
        if ($documento === null) {
            return trim(preg_replace('/\s+/u', ' ', $nodo->textContent) ?? $nodo->textContent);
        }

        $xpath = new DOMXPath($documento);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $texto = '';
        foreach ($xpath->query('.//w:t', $nodo) ?: [] as $t) {
            $texto .= $t->textContent;
        }

        return trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);
    }

    /**
     * @param  array<int, string>  $celdas
     * @param  array<int, string>  $candidatos
     */
    private function indiceColumna(array $celdas, array $candidatos): ?int
    {
        foreach ($celdas as $i => $celda) {
            foreach ($candidatos as $candidato) {
                if ($celda === $candidato || str_contains($celda, $candidato)) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function normalizarEncabezadoCelda(string $texto): string
    {
        $texto = mb_strtoupper(trim($texto));
        $texto = strtr($texto, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
            'Ü' => 'U',
        ]);

        return preg_replace('/\s+/u', ' ', $texto) ?? $texto;
    }

    private function esEncabezadoListado(string $linea): bool
    {
        $normalizada = mb_strtoupper($linea);

        return str_contains($normalizada, 'CANTIDAD')
            && (str_contains($normalizada, 'NOMBRE') || str_contains($normalizada, 'PRODUCTO'));
    }

    private function esRuidoListado(string $linea): bool
    {
        $normalizada = mb_strtoupper($linea);

        return str_starts_with($normalizada, 'LISTA DE MATERIALES')
            || str_starts_with($normalizada, 'NOTA:');
    }
}

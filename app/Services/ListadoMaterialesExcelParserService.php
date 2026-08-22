<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use ZipArchive;

class ListadoMaterialesExcelParserService
{
    /**
     * @return array{
     *   cabecera: array{codigo_cotizacion: string, empresa: string, rutempresa: string, nombre: string},
     *   lineas: array<int, array{cantidad: int, descripcion: string}>,
     *   omitidas: int
     * }
     */
    public function parseDocumentoCompleto(
        UploadedFile $file,
        string $columnaDescripcion,
        string $columnaCantidad,
    ): array {
        $path = $file->getRealPath() ?: $file->getPathname();
        if (! is_string($path) || ! is_readable($path)) {
            throw new RuntimeException('No se pudo leer el archivo Excel.');
        }

        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: pathinfo($path, PATHINFO_EXTENSION)));
        if (! in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            throw new RuntimeException('Formato no soportado. Use .xlsx, .xls o .csv.');
        }

        $idxDescripcion = $this->indiceColumna($columnaDescripcion);
        $idxCantidad = $this->indiceColumna($columnaCantidad);
        if ($idxDescripcion >= 1 && $idxCantidad >= 1 && $idxDescripcion === $idxCantidad) {
            throw new RuntimeException('La columna de descripción y de cantidad deben ser distintas.');
        }

        $lineas = [];
        $omitidas = 0;

        if ($extension === 'xlsx') {
            $desdeXml = $this->parseTodasLasHojasXml($path, $idxDescripcion, $idxCantidad);
            $lineas = $desdeXml['lineas'];
            $omitidas = $desdeXml['omitidas'];
        }

        if ($lineas === []) {
            try {
                $desdeLibro = $this->parseConPhpSpreadsheet($path, $idxDescripcion, $idxCantidad);
                $lineas = $desdeLibro['lineas'];
                $omitidas = $desdeLibro['omitidas'];
            } catch (RuntimeException $e) {
                if ($extension !== 'xlsx' || $lineas !== []) {
                    throw $e;
                }
            }
        }

        if ($lineas === []) {
            throw new RuntimeException(
                'No se detectaron productos con descripción y cantidad válidas. Revise las columnas indicadas y el archivo.',
            );
        }

        return [
            'cabecera' => [
                'codigo_cotizacion' => '',
                'empresa' => '',
                'rutempresa' => '',
                'nombre' => '',
            ],
            'lineas' => $lineas,
            'omitidas' => $omitidas,
        ];
    }

    public function indiceColumna(string $valor): int
    {
        $valor = strtoupper(trim($valor));
        if ($valor === '') {
            return 0;
        }

        if (ctype_digit($valor)) {
            return max(0, (int) $valor);
        }

        if (preg_match('/^[A-Z]{1,3}$/', $valor) !== 1) {
            return 0;
        }

        try {
            return Coordinate::columnIndexFromString($valor);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array{lineas: array<int, array{cantidad: int, descripcion: string}>, omitidas: int}
     */
    private function parseConPhpSpreadsheet(string $path, int $idxDescripcion, int $idxCantidad): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            if (method_exists($reader, 'setReadDataOnly')) {
                $reader->setReadDataOnly(true);
            }
            if (method_exists($reader, 'setIncludeCharts')) {
                $reader->setIncludeCharts(false);
            }
            $spreadsheet = $reader->load($path);
        } catch (\Throwable $e) {
            throw new RuntimeException('No se pudo abrir el Excel: '.$e->getMessage(), 0, $e);
        }

        $lineas = [];
        $omitidas = 0;
        $mapeoPorLetra = $idxDescripcion >= 1 && $idxCantidad >= 1;

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $parse = $mapeoPorLetra
                ? $this->parseHoja($sheet, $idxDescripcion, $idxCantidad)
                : ['lineas' => [], 'omitidas' => 0];
            $lineas = array_merge($lineas, $parse['lineas']);
            $omitidas += $parse['omitidas'];
        }

        if ($lineas === []) {
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $detectadas = $this->detectarColumnas($sheet);
                if ($detectadas === null) {
                    continue;
                }
                if ($mapeoPorLetra
                    && $detectadas['descripcion'] === $idxDescripcion
                    && $detectadas['cantidad'] === $idxCantidad
                ) {
                    continue;
                }
                $parse = $this->parseHoja($sheet, $detectadas['descripcion'], $detectadas['cantidad']);
                $lineas = array_merge($lineas, $parse['lineas']);
                $omitidas += $parse['omitidas'];
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return ['lineas' => $lineas, 'omitidas' => $omitidas];
    }

    /**
     * Lee celdas directo del XML del .xlsx (ignora dimensión mal grabada e imágenes).
     *
     * @return array{lineas: array<int, array{cantidad: int, descripcion: string}>, omitidas: int}
     */
    private function parseTodasLasHojasXml(string $path, int $idxDescripcion, int $idxCantidad): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return ['lineas' => [], 'omitidas' => 0];
        }

        try {
            $strings = $this->leerSharedStringsXlsx($zip);
            $lineas = [];
            $omitidas = 0;
            $mapeoPorLetra = $idxDescripcion >= 1 && $idxCantidad >= 1;

            foreach ($this->rutasHojasXlsx($zip) as $ruta) {
                $grid = $this->grillaDesdeHojaXml($zip, $ruta, $strings);
                if ($grid === []) {
                    continue;
                }

                $parse = ['lineas' => [], 'omitidas' => 0];
                if ($mapeoPorLetra) {
                    $parse = $this->parseGrilla($grid, $idxDescripcion, $idxCantidad);
                }
                if ($parse['lineas'] === []) {
                    $detectadas = $this->detectarColumnasEnGrilla($grid);
                    if ($detectadas !== null
                        && ! ($mapeoPorLetra
                            && $detectadas['descripcion'] === $idxDescripcion
                            && $detectadas['cantidad'] === $idxCantidad)
                    ) {
                        $parse = $this->parseGrilla($grid, $detectadas['descripcion'], $detectadas['cantidad']);
                    }
                }

                $lineas = array_merge($lineas, $parse['lineas']);
                $omitidas += $parse['omitidas'];
            }

            return ['lineas' => $lineas, 'omitidas' => $omitidas];
        } finally {
            $zip->close();
        }
    }

    /**
     * @return list<string>
     */
    private function rutasHojasXlsx(ZipArchive $zip): array
    {
        $rutas = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombre = (string) $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', str_replace('\\', '/', $nombre)) === 1) {
                $rutas[] = $nombre;
            }
        }
        sort($rutas, SORT_NATURAL);

        return $rutas;
    }

    /**
     * @return list<string>
     */
    private function leerSharedStringsXlsx(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (! is_string($xml) || $xml === '') {
            return [];
        }

        $dom = new DOMDocument;
        if (! @$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return [];
        }

        $strings = [];
        foreach ($dom->getElementsByTagName('si') as $si) {
            $partes = [];
            foreach ($si->getElementsByTagName('t') as $nodo) {
                $partes[] = (string) $nodo->textContent;
            }
            $strings[] = trim(implode('', $partes));
        }

        return $strings;
    }

    /**
     * @param  list<string>  $strings
     * @return array<int, array<int, string>>
     */
    private function grillaDesdeHojaXml(ZipArchive $zip, string $ruta, array $strings): array
    {
        $xml = $zip->getFromName($ruta);
        if (! is_string($xml) || $xml === '') {
            return [];
        }

        $dom = new DOMDocument;
        if (! @$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return [];
        }

        $grid = [];
        foreach ($dom->getElementsByTagName('c') as $celda) {
            if (! $celda instanceof DOMElement) {
                continue;
            }
            $ref = strtoupper(trim($celda->getAttribute('r')));
            if (preg_match('/^([A-Z]+)(\d+)$/', $ref, $m) !== 1) {
                continue;
            }
            try {
                $col = Coordinate::columnIndexFromString($m[1]);
            } catch (\Throwable) {
                continue;
            }
            $row = (int) $m[2];
            $valor = $this->valorCeldaXml($celda, $celda->getAttribute('t'), $strings);
            if ($valor === '') {
                continue;
            }
            $grid[$row][$col] = $valor;
        }

        return $grid;
    }

    /**
     * @param  list<string>  $strings
     */
    private function valorCeldaXml(DOMElement $celda, string $tipo, array $strings): string
    {
        $tipo = strtolower(trim($tipo));
        if ($tipo === 's') {
            $idx = (int) $this->textoHijoXml($celda, 'v');

            return $this->valorATexto($strings[$idx] ?? '');
        }
        if ($tipo === 'inlineStr' || $tipo === 'inlinestr') {
            $partes = [];
            foreach ($celda->getElementsByTagName('t') as $nodo) {
                $partes[] = (string) $nodo->textContent;
            }

            return $this->valorATexto(trim(implode('', $partes)));
        }
        if ($tipo === 'b') {
            $raw = $this->textoHijoXml($celda, 'v');

            return $raw === '1' || strtolower($raw) === 'true' ? '1' : '0';
        }

        return $this->valorATexto($this->textoHijoXml($celda, 'v'));
    }

    private function textoHijoXml(DOMElement $celda, string $tag): string
    {
        foreach ($celda->childNodes as $hijo) {
            if ($hijo instanceof DOMElement && strtolower($hijo->localName) === strtolower($tag)) {
                return trim((string) $hijo->textContent);
            }
        }

        return '';
    }

    /**
     * @param  array<int, array<int, string>>  $grid
     * @return array{lineas: array<int, array{cantidad: int, descripcion: string}>, omitidas: int}
     */
    private function parseGrilla(array $grid, int $idxDescripcion, int $idxCantidad): array
    {
        $lineas = [];
        $omitidas = 0;
        $maxRow = $grid === [] ? 0 : (int) max(array_keys($grid));

        for ($row = 1; $row <= $maxRow; $row++) {
            $descripcionRaw = (string) ($grid[$row][$idxDescripcion] ?? '');
            $cantidadRaw = (string) ($grid[$row][$idxCantidad] ?? '');
            $linea = $this->lineaDesdeCeldas($descripcionRaw, $cantidadRaw);
            if ($linea === null) {
                $omitidas++;

                continue;
            }
            $lineas[] = $linea;
        }

        return ['lineas' => $lineas, 'omitidas' => $omitidas];
    }

    /**
     * @param  array<int, array<int, string>>  $grid
     * @return array{descripcion: int, cantidad: int}|null
     */
    private function detectarColumnasEnGrilla(array $grid): ?array
    {
        $maxCol = 1;
        $maxRow = 1;
        foreach ($grid as $row => $cols) {
            $maxRow = max($maxRow, (int) $row);
            foreach (array_keys($cols) as $col) {
                $maxCol = max($maxCol, (int) $col);
            }
        }
        if ($maxCol < 2 || $maxRow < 1) {
            return null;
        }

        $limite = min($maxRow, 25);
        for ($row = 1; $row <= $limite; $row++) {
            $idxCantidad = 0;
            $idxDescripcion = 0;
            $scoreCantidad = 0;
            $scoreDescripcion = 0;
            for ($col = 1; $col <= $maxCol; $col++) {
                $texto = $this->normalizarEncabezado((string) ($grid[$row][$col] ?? ''));
                if ($texto === '') {
                    continue;
                }
                $scoreCant = $this->puntajeEncabezadoCantidad($texto);
                $scoreDesc = $this->puntajeEncabezadoProducto($texto);
                if ($scoreCant > $scoreCantidad) {
                    $scoreCantidad = $scoreCant;
                    $idxCantidad = $col;
                }
                if ($scoreDesc > $scoreDescripcion) {
                    $scoreDescripcion = $scoreDesc;
                    $idxDescripcion = $col;
                }
            }
            if ($idxCantidad >= 1 && $idxDescripcion >= 1 && $idxCantidad !== $idxDescripcion
                && $scoreCantidad > 0 && $scoreDescripcion > 0
            ) {
                return [
                    'descripcion' => $idxDescripcion,
                    'cantidad' => $idxCantidad,
                ];
            }
        }

        $mejorDesc = 0;
        $mejorCant = 0;
        $scoreDesc = 0;
        $scoreCant = 0;
        for ($col = 1; $col <= $maxCol; $col++) {
            $textos = 0;
            $numeros = 0;
            $unidad = 0;
            $muestras = 0;
            for ($row = 1; $row <= min($maxRow, 80); $row++) {
                $valor = (string) ($grid[$row][$col] ?? '');
                if (trim($valor) === '') {
                    continue;
                }
                $muestras++;
                $upper = mb_strtoupper(trim($valor));
                if (in_array($upper, ['UNIDAD', 'UNID', 'U', 'UND'], true)) {
                    $unidad++;

                    continue;
                }
                if ($this->puntajeEncabezadoCantidad($this->normalizarEncabezado($valor)) > 0
                    || $this->puntajeEncabezadoProducto($this->normalizarEncabezado($valor)) > 0
                ) {
                    continue;
                }
                if ($this->parseCantidad($valor) !== null && mb_strlen($valor) <= 12) {
                    $numeros++;

                    continue;
                }
                if (mb_strlen($this->normalizarDescripcion($valor)) >= 8) {
                    $textos++;
                }
            }
            if ($muestras === 0 || $unidad >= max(2, (int) floor($muestras * 0.6))) {
                continue;
            }
            if ($numeros > $scoreCant && $numeros >= 2 && $numeros >= $textos) {
                $scoreCant = $numeros;
                $mejorCant = $col;
            }
            if ($textos > $scoreDesc && $textos >= 2 && $textos > $numeros) {
                $scoreDesc = $textos;
                $mejorDesc = $col;
            }
        }

        if ($mejorDesc >= 1 && $mejorCant >= 1 && $mejorDesc !== $mejorCant) {
            return [
                'descripcion' => $mejorDesc,
                'cantidad' => $mejorCant,
            ];
        }

        return null;
    }

    /**
     * @return array{descripcion: string, cantidad: int}|null
     */
    private function lineaDesdeCeldas(string $descripcionRaw, string $cantidadRaw): ?array
    {
        if ($this->filaVacia($descripcionRaw, $cantidadRaw) || $this->esFilaBasura($descripcionRaw, $cantidadRaw)) {
            return null;
        }
        $cantidad = $this->parseCantidad($cantidadRaw);
        if ($cantidad === null) {
            return null;
        }
        $descripcion = $this->normalizarDescripcion($descripcionRaw);
        if ($descripcion === '') {
            return null;
        }

        return [
            'descripcion' => $descripcion,
            'cantidad' => max(1, $cantidad),
        ];
    }

    /**
     * @return array{lineas: array<int, array{cantidad: int, descripcion: string}>, omitidas: int}
     */
    private function parseHoja(Worksheet $sheet, int $idxDescripcion, int $idxCantidad): array
    {
        $highestRow = $this->highestRow($sheet);
        $lineas = [];
        $omitidas = 0;

        for ($row = 1; $row <= $highestRow; $row++) {
            $descripcionRaw = $this->valorCelda($sheet, $idxDescripcion, $row);
            $cantidadRaw = $this->valorCelda($sheet, $idxCantidad, $row);

            if ($this->filaVacia($descripcionRaw, $cantidadRaw)) {
                $omitidas++;

                continue;
            }

            if ($this->esFilaBasura($descripcionRaw, $cantidadRaw)) {
                $omitidas++;

                continue;
            }

            $cantidad = $this->parseCantidad($cantidadRaw);
            if ($cantidad === null) {
                $omitidas++;

                continue;
            }

            $descripcion = $this->normalizarDescripcion($descripcionRaw);
            if ($descripcion === '') {
                $omitidas++;

                continue;
            }

            $lineas[] = [
                'descripcion' => $descripcion,
                'cantidad' => max(1, $cantidad),
            ];
        }

        return ['lineas' => $lineas, 'omitidas' => $omitidas];
    }

    /**
     * @return array{descripcion: int, cantidad: int}|null
     */
    private function detectarColumnas(Worksheet $sheet): ?array
    {
        return $this->detectarColumnasDesdeEncabezados($sheet)
            ?? $this->detectarColumnasPorContenido($sheet);
    }

    /**
     * @return array{descripcion: int, cantidad: int}|null
     */
    private function detectarColumnasDesdeEncabezados(Worksheet $sheet): ?array
    {
        $highestColumn = $this->highestColumn($sheet);
        $limite = min($this->highestRow($sheet), 25);
        if ($highestColumn < 2 || $limite < 1) {
            return null;
        }

        for ($row = 1; $row <= $limite; $row++) {
            $idxCantidad = 0;
            $idxDescripcion = 0;
            $scoreCantidad = 0;
            $scoreDescripcion = 0;

            for ($col = 1; $col <= $highestColumn; $col++) {
                $texto = $this->normalizarEncabezado($this->valorCelda($sheet, $col, $row));
                if ($texto === '') {
                    continue;
                }

                $scoreCant = $this->puntajeEncabezadoCantidad($texto);
                $scoreDesc = $this->puntajeEncabezadoProducto($texto);
                if ($scoreCant > $scoreCantidad) {
                    $scoreCantidad = $scoreCant;
                    $idxCantidad = $col;
                }
                if ($scoreDesc > $scoreDescripcion) {
                    $scoreDescripcion = $scoreDesc;
                    $idxDescripcion = $col;
                }
            }

            if ($idxCantidad >= 1 && $idxDescripcion >= 1 && $idxCantidad !== $idxDescripcion
                && $scoreCantidad > 0 && $scoreDescripcion > 0
            ) {
                return [
                    'descripcion' => $idxDescripcion,
                    'cantidad' => $idxCantidad,
                ];
            }
        }

        return null;
    }

    /**
     * @return array{descripcion: int, cantidad: int}|null
     */
    private function detectarColumnasPorContenido(Worksheet $sheet): ?array
    {
        $highestColumn = $this->highestColumn($sheet);
        $highestRow = $this->highestRow($sheet);
        if ($highestColumn < 2 || $highestRow < 2) {
            return null;
        }

        $mejorDesc = 0;
        $mejorCant = 0;
        $scoreDesc = 0;
        $scoreCant = 0;

        for ($col = 1; $col <= $highestColumn; $col++) {
            $textos = 0;
            $numeros = 0;
            $unidad = 0;
            $muestras = 0;

            for ($row = 1; $row <= min($highestRow, 80); $row++) {
                $valor = $this->valorCelda($sheet, $col, $row);
                if (trim($valor) === '') {
                    continue;
                }
                $muestras++;
                $upper = mb_strtoupper(trim($valor));
                if (in_array($upper, ['UNIDAD', 'UNID', 'U', 'UND'], true)) {
                    $unidad++;

                    continue;
                }
                if ($this->puntajeEncabezadoCantidad($this->normalizarEncabezado($valor)) > 0
                    || $this->puntajeEncabezadoProducto($this->normalizarEncabezado($valor)) > 0
                ) {
                    continue;
                }
                if ($this->parseCantidad($valor) !== null && mb_strlen($valor) <= 12) {
                    $numeros++;

                    continue;
                }
                if (mb_strlen($this->normalizarDescripcion($valor)) >= 8) {
                    $textos++;
                }
            }

            if ($muestras === 0 || $unidad >= max(2, (int) floor($muestras * 0.6))) {
                continue;
            }
            if ($numeros > $scoreCant && $numeros >= 2 && $numeros >= $textos) {
                $scoreCant = $numeros;
                $mejorCant = $col;
            }
            if ($textos > $scoreDesc && $textos >= 2 && $textos > $numeros) {
                $scoreDesc = $textos;
                $mejorDesc = $col;
            }
        }

        if ($mejorDesc >= 1 && $mejorCant >= 1 && $mejorDesc !== $mejorCant) {
            return [
                'descripcion' => $mejorDesc,
                'cantidad' => $mejorCant,
            ];
        }

        return null;
    }

    private function highestRow(Worksheet $sheet): int
    {
        return max(
            1,
            (int) $sheet->getHighestDataRow(),
            (int) $sheet->getHighestRow(),
        );
    }

    private function highestColumn(Worksheet $sheet): int
    {
        $letras = array_filter([
            (string) $sheet->getHighestDataColumn(),
            (string) $sheet->getHighestColumn(),
        ]);
        $max = 1;
        foreach ($letras as $letra) {
            try {
                $max = max($max, Coordinate::columnIndexFromString($letra));
            } catch (\Throwable) {
                // ignorar
            }
        }

        return $max;
    }

    private function valorCelda(Worksheet $sheet, int $colIndex, int $row): string
    {
        $coord = Coordinate::stringFromColumnIndex($colIndex).$row;
        $cell = $sheet->getCell($coord);
        $value = null;

        try {
            $value = $cell->getCalculatedValue();
        } catch (\Throwable) {
            $value = null;
        }

        if ($this->celdaEsVacia($value)) {
            $value = $cell->getValue();
        }
        if ($this->celdaEsVacia($value)) {
            try {
                $value = $cell->getFormattedValue();
            } catch (\Throwable) {
                $value = null;
            }
        }

        return $this->valorATexto($value);
    }

    private function celdaEsVacia(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        if ($value instanceof RichText) {
            return trim($value->getPlainText()) === '';
        }

        return false;
    }

    private function valorATexto(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($value instanceof RichText) {
            return trim($value->getPlainText());
        }

        if (is_float($value) || is_int($value)) {
            if (is_float($value) && floor($value) == $value) {
                return (string) (int) $value;
            }

            return rtrim(rtrim(sprintf('%.8F', (float) $value), '0'), '.');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }

    private function filaVacia(string $descripcion, string $cantidad): bool
    {
        return trim($descripcion) === '' && trim($cantidad) === '';
    }

    private function esFilaBasura(string $descripcionRaw, string $cantidadRaw): bool
    {
        $desc = $this->normalizarDescripcion($descripcionRaw);
        $cant = trim($cantidadRaw);
        if ($desc === '') {
            return true;
        }

        $upper = mb_strtoupper($desc);
        $cantUpper = mb_strtoupper($cant);

        foreach ([
            'ANEXO 1',
            'ANEXO',
            'OFERTA ECONOMICA',
            'OFERTA ECONÓMICA',
            'TOTAL NETO',
            'TOTAL',
            '$UNITARIO NETO',
            'UNITARIO NETO',
            'UNIDAD',
            'UNID',
            'CANTIDAD',
            'CANTIDAD INICIAL',
            'DESCRIPCION',
            'DESCRIPCIÓN',
            'PRODUCTO',
            'IMAGEN',
            'OBSERVACIONES',
        ] as $exacto) {
            if ($upper === $exacto || $cantUpper === $exacto) {
                return true;
            }
        }

        if (str_starts_with($upper, 'PRODUCTO ESCUELA')
            || (str_starts_with($upper, 'PRODUCTO ') && str_contains($upper, 'ESCUELA'))
            || str_starts_with($upper, 'ANEXO')
            || str_starts_with($upper, 'OFERTA ECON')
        ) {
            return true;
        }

        if (preg_match('/^TOTAL(\s+NETO)?$/u', $upper) === 1) {
            return true;
        }

        return false;
    }

    private function parseCantidad(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '-' || $raw === '—') {
            return null;
        }

        $upper = mb_strtoupper($raw);
        if (in_array($upper, ['CANTIDAD', 'CANTIDAD INICIAL', 'TOTAL', 'TOTAL NETO', 'UNIDAD', 'UNID'], true)) {
            return null;
        }

        $normalizado = str_replace(["\xc2\xa0", ' '], '', $raw);
        $normalizado = str_replace(['.', ','], ['', '.'], $normalizado);
        if (! is_numeric($normalizado)) {
            if (preg_match('/^\d+([.,]\d+)?$/', preg_replace('/[^\d.,]/', '', $raw) ?? '') !== 1) {
                return null;
            }
            $solo = preg_replace('/[^\d.,]/', '', $raw) ?? '';
            $solo = str_replace(['.', ','], ['', '.'], $solo);
            if (! is_numeric($solo)) {
                return null;
            }
            $normalizado = $solo;
        }

        $valor = (float) $normalizado;
        if ($valor <= 0) {
            return null;
        }

        return (int) round($valor);
    }

    private function normalizarDescripcion(string $descripcion): string
    {
        $descripcion = trim(preg_replace('/\s+/u', ' ', $descripcion) ?? $descripcion);

        return mb_substr($descripcion, 0, 500);
    }

    private function normalizarEncabezado(string $texto): string
    {
        $texto = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto));
        $texto = strtr($texto, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);

        return $texto;
    }

    private function puntajeEncabezadoCantidad(string $texto): int
    {
        if ($texto === '' || str_starts_with($texto, 'UNID')) {
            return 0;
        }
        if (str_contains($texto, 'CANTIDAD INICIAL')) {
            return 4;
        }
        if ($texto === 'CANTIDAD' || str_starts_with($texto, 'CANTIDAD')) {
            return 3;
        }
        if (in_array($texto, ['CANT', 'CANT.', 'QTY', 'QUANTITY'], true)) {
            return 1;
        }

        return 0;
    }

    private function puntajeEncabezadoProducto(string $texto): int
    {
        if ($texto === '' || str_starts_with($texto, 'UNID') || str_starts_with($texto, 'CANTIDAD')) {
            return 0;
        }
        if (in_array($texto, ['IMAGEN', 'OBS', 'OBSERVACIONES', 'FOTO'], true)) {
            return 0;
        }
        if (str_starts_with($texto, 'DESCRIPC')) {
            return 4;
        }
        if ($texto === 'PRODUCTO' || str_starts_with($texto, 'PRODUCTO')) {
            return 3;
        }
        if (in_array($texto, ['DETALLE', 'GLOSA', 'ITEM', 'ARTICULO'], true)) {
            return 2;
        }

        return 0;
    }
}

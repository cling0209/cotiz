<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

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
        if ($idxDescripcion < 1 || $idxCantidad < 1) {
            throw new RuntimeException('Indique columnas válidas (letra A, B, C… o número 1, 2, 3…).');
        }
        if ($idxDescripcion === $idxCantidad) {
            throw new RuntimeException('La columna de descripción y de cantidad deben ser distintas.');
        }

        try {
            $reader = IOFactory::createReaderForFile($path);
            if (method_exists($reader, 'setReadDataOnly')) {
                $reader->setReadDataOnly(true);
            }
            $spreadsheet = $reader->load($path);
        } catch (\Throwable $e) {
            throw new RuntimeException('No se pudo abrir el Excel: '.$e->getMessage(), 0, $e);
        }

        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = (int) $sheet->getHighestDataRow();
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

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

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

        if (preg_match('/^[A-Z]+$/', $valor) !== 1) {
            return 0;
        }

        try {
            return Coordinate::columnIndexFromString($valor);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function valorCelda(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $colIndex, int $row): string
    {
        $coord = Coordinate::stringFromColumnIndex($colIndex).$row;
        $value = $sheet->getCell($coord)->getCalculatedValue();

        if ($value === null) {
            return '';
        }

        if (is_float($value) || is_int($value)) {
            if (is_float($value) && floor($value) == $value) {
                return (string) (int) $value;
            }

            return rtrim(rtrim(sprintf('%.8F', (float) $value), '0'), '.');
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
            'CANTIDAD',
            'DESCRIPCION',
            'DESCRIPCIÓN',
            'PRODUCTO',
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
        if (in_array($upper, ['CANTIDAD', 'TOTAL', 'TOTAL NETO', 'UNIDAD'], true)) {
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
}

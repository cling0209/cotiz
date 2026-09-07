<?php

namespace App\Services;

use App\Models\Maeprod;
use App\Support\ProductCodeNormalizer;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class MaeprodBulkDeleteService
{
    private const MAX_ROWS = 5000;

    /**
     * @return array{
     *     deleted: int,
     *     not_deleted: int,
     *     total: int,
     *     failures: list<array{fila: int, codigo: string, motivo: string}>
     * }
     */
    public function deleteFromUpload(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if ($path === false || $path === '') {
            throw new RuntimeException('No se pudo leer el archivo subido.');
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());

        return $this->deleteFromPath($path, $extension);
    }

    /**
     * @return array{
     *     deleted: int,
     *     not_deleted: int,
     *     total: int,
     *     failures: list<array{fila: int, codigo: string, motivo: string}>
     * }
     */
    public function deleteFromPath(string $path, string $extension = ''): array
    {
        $codes = $this->parseCodes($path, $extension);

        $deleted = 0;
        $failures = [];
        $seen = [];

        foreach ($codes as $entry) {
            $fila = $entry['fila'];
            $codigo = $entry['codigo'];

            if ($codigo === '') {
                $failures[] = [
                    'fila' => $fila,
                    'codigo' => '',
                    'motivo' => 'Código vacío',
                ];
                continue;
            }

            if (isset($seen[$codigo])) {
                $failures[] = [
                    'fila' => $fila,
                    'codigo' => $codigo,
                    'motivo' => 'Código duplicado en el archivo (ya procesado en fila '.$seen[$codigo].')',
                ];
                continue;
            }

            $seen[$codigo] = $fila;

            $producto = Maeprod::query()->find($codigo);
            if ($producto === null) {
                $failures[] = [
                    'fila' => $fila,
                    'codigo' => $codigo,
                    'motivo' => 'Producto no encontrado en el maestro',
                ];
                continue;
            }

            try {
                $producto->delete();
                $deleted++;
            } catch (Throwable $e) {
                report($e);
                $failures[] = [
                    'fila' => $fila,
                    'codigo' => $codigo,
                    'motivo' => 'Error al eliminar: '.$e->getMessage(),
                ];
            }
        }

        return [
            'deleted' => $deleted,
            'not_deleted' => count($failures),
            'total' => count($codes),
            'failures' => $failures,
        ];
    }

    public function templateExcelDownloadResponse(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->fromArray([
                ['codigo'],
                ['DEMO001'],
            ]);

            (new Xlsx($spreadsheet))->save('php://output');
        }, 'plantilla_eliminacion_masiva_productos.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @return list<array{fila: int, codigo: string}>
     */
    private function parseCodes(string $path, string $extension): array
    {
        if ($extension === '') {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        }

        if (in_array($extension, ['csv', 'txt'], true)) {
            return $this->parseCsv($path);
        }

        return $this->parseSpreadsheet($path);
    }

    /**
     * @return list<array{fila: int, codigo: string}>
     */
    private function parseSpreadsheet(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }

        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = (int) $sheet->getHighestDataRow();

        if ($highestRow < 1) {
            throw new RuntimeException('El archivo no tiene filas.');
        }

        if ($highestRow > self::MAX_ROWS + 1) {
            throw new RuntimeException('El archivo supera el máximo de '.self::MAX_ROWS.' códigos.');
        }

        $headerRow = [];
        $highestColumn = $sheet->getHighestDataColumn();
        $columnCount = Coordinate::columnIndexFromString($highestColumn);

        for ($col = 1; $col <= $columnCount; $col++) {
            $headerRow[] = ProductCodeNormalizer::normalize(
                $sheet->getCell(Coordinate::stringFromColumnIndex($col).'1')->getValue()
            );
        }

        $codigoCol = $this->resolveCodigoColumn($headerRow);
        $startRow = $codigoCol !== null ? 2 : 1;
        $colIndex = $codigoCol ?? 0;

        if ($codigoCol === null && $this->looksLikeHeader($headerRow[0] ?? '')) {
            $startRow = 2;
        }

        $entries = [];
        for ($row = $startRow; $row <= $highestRow; $row++) {
            $raw = $sheet->getCell(Coordinate::stringFromColumnIndex($colIndex + 1).$row)->getValue();
            $codigo = ProductCodeNormalizer::normalize($raw);

            if ($codigo === '' && ($raw === null || trim((string) $raw) === '')) {
                // Fila completamente vacía: omitir sin contar como fallo.
                $allEmpty = true;
                for ($col = 1; $col <= $columnCount; $col++) {
                    $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($col).$row)->getValue();
                    if ($cell !== null && trim((string) $cell) !== '') {
                        $allEmpty = false;
                        break;
                    }
                }
                if ($allEmpty) {
                    continue;
                }
            }

            $entries[] = [
                'fila' => $row,
                'codigo' => $codigo,
            ];
        }

        if ($entries === []) {
            throw new RuntimeException('No se encontraron códigos en el archivo.');
        }

        return $entries;
    }

    /**
     * @return list<array{fila: int, codigo: string}>
     */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el archivo CSV.');
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            throw new RuntimeException('El archivo CSV está vacío.');
        }

        $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine) ?? $firstLine;
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        rewind($handle);
        $header = fgetcsv($handle, 0, $delimiter);
        if ($header === false) {
            fclose($handle);
            throw new RuntimeException('El archivo CSV está vacío.');
        }

        $header = array_map(
            static fn ($value) => ProductCodeNormalizer::normalize($value),
            $header
        );

        $codigoCol = $this->resolveCodigoColumn($header);
        $entries = [];
        $fila = 1;

        if ($codigoCol === null) {
            // Sin encabezado reconocido: la primera fila es un código.
            $codigo = ProductCodeNormalizer::normalize($header[0] ?? '');
            if ($codigo !== '' || $this->rowHasContent($header)) {
                if (! $this->looksLikeHeader($header[0] ?? '')) {
                    $entries[] = ['fila' => 1, 'codigo' => $codigo];
                }
            }
            $colIndex = 0;
        } else {
            $colIndex = $codigoCol;
        }

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $fila++;
            if ($fila > self::MAX_ROWS + 1) {
                fclose($handle);
                throw new RuntimeException('El archivo supera el máximo de '.self::MAX_ROWS.' códigos.');
            }

            if (! $this->rowHasContent($row)) {
                continue;
            }

            $entries[] = [
                'fila' => $fila,
                'codigo' => ProductCodeNormalizer::normalize($row[$colIndex] ?? ''),
            ];
        }

        fclose($handle);

        if ($entries === []) {
            throw new RuntimeException('No se encontraron códigos en el archivo.');
        }

        return $entries;
    }

    /**
     * @param  list<string>  $header
     */
    private function resolveCodigoColumn(array $header): ?int
    {
        $aliases = ['codigo', 'código', 'prod_item', 'cod', 'sku', 'item'];

        foreach ($header as $index => $value) {
            $normalized = mb_strtolower(trim((string) $value));
            $normalized = str_replace([' ', '-'], '_', $normalized);
            if (in_array($normalized, $aliases, true)) {
                return (int) $index;
            }
        }

        return null;
    }

    private function looksLikeHeader(string $value): bool
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = str_replace([' ', '-'], '_', $normalized);

        return in_array($normalized, ['codigo', 'código', 'prod_item', 'cod', 'sku', 'item'], true);
    }

    /**
     * @param  list<mixed>  $row
     */
    private function rowHasContent(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return true;
            }
        }

        return false;
    }
}

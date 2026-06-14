<?php

namespace App\Services;

use App\Support\MaeprodImportFileTypes;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

class MaeprodOpenSpoutReader
{
    /**
     * @return array{highest_row: int, headers: list<string>, column_count: int}
     */
    public function readMetadata(string $path): array
    {
        if (! is_file($path)) {
            return ['highest_row' => 0, 'headers' => [], 'column_count' => 0];
        }

        $reader = $this->createReader($path);
        $reader->open($path);

        try {
            $headers = [];
            $dataRows = 0;

            foreach ($reader->getSheetIterator() as $sheet) {
                $physicalLine = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $physicalLine++;
                    $values = $this->rowToValues($row);

                    if ($physicalLine === 1) {
                        foreach ($values as $header) {
                            $headers[] = mb_strtolower(trim($this->stripBom((string) $header)));
                        }

                        continue;
                    }

                    if (! $this->isEmptyCsvRow($values)) {
                        $dataRows++;
                    }
                }

                break;
            }

            $dataHeaders = array_values(array_filter($headers, fn (string $header) => $header !== ''));

            return [
                'highest_row' => $dataRows + 1,
                'headers' => $headers,
                'column_count' => count($headers),
            ];
        } finally {
            $reader->close();
        }
    }

    /**
     * @return array{
     *     rows_written: int,
     *     batch_count: int,
     *     data_headers: list<string>,
     *     header_row: list<string>,
     *     delimiter: string
     * }
     */
    public function streamExcelFile(string $sourcePath, string $jobDir, int $rowsPerBatch): array
    {
        if (! is_file($sourcePath)) {
            throw new \InvalidArgumentException('No se encontró el archivo Excel a importar.');
        }

        $reader = $this->createReader($sourcePath);
        $reader->open($sourcePath);

        $writer = null;
        $dataHeaders = [];
        $headerRow = [];
        $delimiter = ';';
        $rowsWritten = 0;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $physicalLine = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $physicalLine++;
                    $values = $this->rowToValues($row);

                    if ($physicalLine === 1) {
                        $headers = array_map(
                            fn (string $header) => mb_strtolower(trim($this->stripBom($header))),
                            $values,
                        );
                        $dataHeaders = array_values(array_filter($headers, fn (string $header) => $header !== ''));

                        if ($dataHeaders === []) {
                            throw new \InvalidArgumentException('El archivo Excel no contiene encabezados válidos.');
                        }

                        $headerRow = array_merge(['_csv_line'], $dataHeaders);
                        $writer = new MaeprodImportBatchWriter($jobDir, $headerRow, $delimiter, $rowsPerBatch);

                        continue;
                    }

                    if ($this->isEmptyCsvRow($values)) {
                        continue;
                    }

                    if ($writer === null) {
                        throw new \InvalidArgumentException('El archivo Excel no contiene encabezados válidos.');
                    }

                    $lineValues = [(string) $physicalLine];
                    foreach ($dataHeaders as $columnIndex => $header) {
                        $lineValues[] = trim((string) ($values[$columnIndex] ?? ''));
                    }

                    $writer->writeRow($lineValues);
                    $rowsWritten++;
                }

                break;
            }
        } finally {
            $reader->close();
        }

        if ($writer === null) {
            throw new \InvalidArgumentException('El archivo Excel no contiene encabezados válidos.');
        }

        $writer->finalize();

        if ($rowsWritten === 0) {
            throw new \InvalidArgumentException('El archivo no contiene filas de productos.');
        }

        return [
            'rows_written' => $rowsWritten,
            'batch_count' => $writer->getBatchCount(),
            'data_headers' => $dataHeaders,
            'header_row' => $headerRow,
            'delimiter' => $delimiter,
        ];
    }

    public function supportsPath(string $path, ?string $originalName = null): bool
    {
        $extension = MaeprodImportFileTypes::extensionFromName($originalName ?? basename($path));

        return $extension === 'xlsx';
    }

    private function createReader(string $path): ReaderInterface
    {
        return new XlsxReader;
    }

    /**
     * @return list<string>
     */
    private function rowToValues(Row $row): array
    {
        $values = [];

        foreach ($row->getCells() as $cell) {
            $values[] = $this->formatCellValue($cell);
        }

        return $values;
    }

    private function formatCellValue(Cell $cell): string
    {
        $value = $cell->getValue();

        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            if (is_float($value) && floor($value) === $value) {
                return (string) (int) $value;
            }

            return rtrim(rtrim(sprintf('%.10F', (float) $value), '0'), '.');
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }

    /**
     * @param  list<string|null>  $data
     */
    private function isEmptyCsvRow(array $data): bool
    {
        foreach ($data as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function stripBom(string $value): string
    {
        if (str_starts_with($value, "\xEF\xBB\xBF")) {
            return substr($value, 3);
        }

        return $value;
    }
}

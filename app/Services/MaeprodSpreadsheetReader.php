<?php

namespace App\Services;

use App\Support\MaeprodSpreadsheetRowReadFilter;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

class MaeprodSpreadsheetReader
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
        $worksheetInfo = $reader->listWorksheetInfo($path)[0] ?? null;
        $highestRow = max(0, (int) ($worksheetInfo['totalRows'] ?? 0));
        $highestColumn = (string) ($worksheetInfo['lastColumnLetter'] ?? '');
        $columnCount = $highestColumn !== ''
            ? Coordinate::columnIndexFromString($highestColumn)
            : 0;

        $reader->setReadFilter(new MaeprodSpreadsheetRowReadFilter(1, 1));
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $headerRow = $columnCount > 0
            ? $this->readSheetRow($sheet, 1, $columnCount)
            : [];

        $headers = [];
        foreach ($headerRow as $header) {
            $headers[] = mb_strtolower(trim($this->stripBom((string) $header)));
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return [
            'highest_row' => $highestRow,
            'headers' => $headers,
            'column_count' => $columnCount,
        ];
    }

    /**
     * @param  list<string>  $headers
     * @return list<array<string, string>>
     */
    public function readDataRows(string $path, int $startRow, int $endRow, array $headers, int $columnCount): array
    {
        if (! is_file($path) || $startRow > $endRow || $columnCount < 1) {
            return [];
        }

        $reader = $this->createReader($path);
        $reader->setReadFilter(new MaeprodSpreadsheetRowReadFilter($startRow, $endRow));
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = [];

        for ($rowIndex = $startRow; $rowIndex <= $endRow; $rowIndex++) {
            $data = $this->readSheetRow($sheet, $rowIndex, $columnCount);

            if ($this->isEmptyRow($data)) {
                continue;
            }

            $row = ['_csv_line' => (string) $rowIndex];
            foreach ($headers as $columnIndex => $header) {
                if ($header === '') {
                    continue;
                }

                $row[$header] = trim((string) ($data[$columnIndex] ?? ''));
            }

            if (count($row) > 1) {
                $rows[] = $row;
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $rows;
    }

    /**
     * @return list<array<string, string>>
     */
    public function parseFile(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $reader = $this->createReader($path);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();

        if ($highestRow < 1) {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            return [];
        }

        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);
        $headerRow = $this->readSheetRow($sheet, 1, $highestColumnIndex);

        if ($this->isEmptyRow($headerRow)) {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            return [];
        }

        $headers = [];
        foreach ($headerRow as $header) {
            $headers[] = mb_strtolower(trim($this->stripBom((string) $header)));
        }

        $rows = [];

        for ($rowIndex = 2; $rowIndex <= $highestRow; $rowIndex++) {
            $data = $this->readSheetRow($sheet, $rowIndex, $highestColumnIndex);

            if ($this->isEmptyRow($data)) {
                continue;
            }

            $row = ['_csv_line' => $rowIndex];
            foreach ($headers as $columnIndex => $header) {
                if ($header === '') {
                    continue;
                }

                $row[$header] = trim((string) ($data[$columnIndex] ?? ''));
            }

            if (count($row) > 1) {
                $rows[] = $row;
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function readSheetRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $rowIndex, int $columnCount): array
    {
        $values = [];

        for ($columnIndex = 1; $columnIndex <= $columnCount; $columnIndex++) {
            $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($columnIndex).$rowIndex);
            $values[] = $this->formatCellValue($cell->getValue());
        }

        return $values;
    }

    private function formatCellValue(mixed $value): string
    {
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
     * @param  list<mixed>  $data
     */
    private function isEmptyRow(array $data): bool
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

    private function createReader(string $path): \PhpOffice\PhpSpreadsheet\Reader\IReader
    {
        $reader = IOFactory::createReaderForFile($path);

        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }

        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }

        return $reader;
    }
}

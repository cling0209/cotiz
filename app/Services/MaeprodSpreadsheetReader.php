<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

class MaeprodSpreadsheetReader
{
    /**
     * @return list<array<string, string>>
     */
    public function parseFile(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $reader = IOFactory::createReaderForFile($path);

        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }

        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }

        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();

        if ($highestRow < 1) {
            return [];
        }

        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);
        $headerRow = $this->readSheetRow($sheet, 1, $highestColumnIndex);

        if ($this->isEmptyRow($headerRow)) {
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
}

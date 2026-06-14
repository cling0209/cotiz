<?php

namespace App\Services;

use App\Support\MaeprodImportFileTypes;

class MaeprodImportStreamPreparer
{
    public const EXCEL_ROWS_PER_PREPARE_REQUEST = 3000;

    /**
     * @return array{
     *     rows_written: int,
     *     batch_count: int,
     *     data_headers: list<string>,
     *     header_row: list<string>,
     *     delimiter: string
     * }
     */
    public function streamExcelFile(
        string $sourcePath,
        string $jobDir,
        int $rowsPerBatch,
        ?string $originalName = null,
    ): array {
        $openSpout = app(MaeprodOpenSpoutReader::class);

        if ($openSpout->supportsPath($sourcePath, $originalName)) {
            return $openSpout->streamExcelFile($sourcePath, $jobDir, $rowsPerBatch);
        }

        return $this->streamExcelFileWithPhpSpreadsheet($sourcePath, $jobDir, $rowsPerBatch);
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
    private function streamExcelFileWithPhpSpreadsheet(string $sourcePath, string $jobDir, int $rowsPerBatch): array
    {
        $state = [
            'source_path' => $sourcePath,
            'next_row' => 2,
            'processed_rows' => 0,
            'highest_row' => null,
        ];

        do {
            $chunk = $this->streamExcelChunk($state, $jobDir, $rowsPerBatch);
            $state['processed_rows'] = $chunk['rows_written'];
            $state['next_row'] = $chunk['next_row'];
            $state['highest_row'] = $chunk['highest_row'];
            $state['data_headers'] = $chunk['data_headers'];
            $state['header_row'] = $chunk['header_row'];
            $state['delimiter'] = $chunk['delimiter'];
            $state['raw_headers'] = $chunk['data_headers'];
            $state['column_count'] = count($chunk['data_headers']);
            $finished = $chunk['finished'];
        } while (! $finished);

        return [
            'rows_written' => $chunk['rows_written'],
            'batch_count' => $chunk['batch_count'],
            'data_headers' => $chunk['data_headers'],
            'header_row' => $chunk['header_row'],
            'delimiter' => $chunk['delimiter'],
        ];
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
    public function streamCsvFile(
        string $sourcePath,
        string $jobDir,
        int $rowsPerBatch,
        int $resumeRowsWritten = 0,
        ?array $knownHeaderRow = null,
        ?string $knownDelimiter = null,
    ): array {
        if (! is_file($sourcePath)) {
            throw new \InvalidArgumentException('No se encontró el archivo a importar.');
        }

        $handle = fopen($sourcePath, 'rb');

        if ($handle === false) {
            throw new \RuntimeException('No se pudo abrir el archivo a importar.');
        }

        try {
            if ($resumeRowsWritten === 0) {
                $parsed = $this->readCsvHeaderFromHandle($handle);
                $dataHeaders = $parsed['data_headers'];
                $headerRow = $parsed['header_row'];
                $delimiter = $parsed['delimiter'];
            } else {
                if ($knownHeaderRow === null || $knownDelimiter === null) {
                    throw new \InvalidArgumentException('Faltan metadatos para reanudar la preparación CSV.');
                }

                $dataHeaders = array_values(array_filter(
                    $knownHeaderRow,
                    fn (string $header) => $header !== '_csv_line',
                ));
                $headerRow = $knownHeaderRow;
                $delimiter = $knownDelimiter;
                $this->seekCsvDataRow($handle, $delimiter, $resumeRowsWritten + 1);
            }

            if ($dataHeaders === []) {
                throw new \InvalidArgumentException('El archivo no contiene encabezados válidos.');
            }

            $writer = new MaeprodImportBatchWriter(
                $jobDir,
                $headerRow,
                $delimiter,
                $rowsPerBatch,
                $resumeRowsWritten,
            );

            $physicalLine = $resumeRowsWritten + 1;

            while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
                $physicalLine++;

                if ($this->isEmptyCsvRow($data)) {
                    continue;
                }

                $lineValues = [(string) $physicalLine];
                foreach ($dataHeaders as $columnIndex => $header) {
                    $lineValues[] = trim((string) ($data[$columnIndex] ?? ''));
                }

                $writer->writeRow($lineValues);
            }

            $writer->finalize();

            if ($writer->getRowsWritten() === 0) {
                throw new \InvalidArgumentException('El archivo no contiene filas de productos.');
            }

            return [
                'rows_written' => $writer->getRowsWritten(),
                'batch_count' => $writer->getBatchCount(),
                'data_headers' => $dataHeaders,
                'header_row' => $headerRow,
                'delimiter' => $delimiter,
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{
     *     rows_written: int,
     *     batch_count: int,
     *     next_row: int,
     *     finished: bool,
     *     data_headers: list<string>,
     *     header_row: list<string>,
     *     delimiter: string,
     *     highest_row: int
     * }
     */
    public function streamExcelChunk(array $state, string $jobDir, int $rowsPerBatch): array
    {
        $sourcePath = (string) ($state['source_path'] ?? '');

        if (! is_file($sourcePath)) {
            throw new \InvalidArgumentException('No se encontró el archivo Excel a importar.');
        }

        $processedRows = (int) ($state['processed_rows'] ?? 0);
        $nextRow = (int) ($state['next_row'] ?? 2);
        $highestRow = (int) ($state['highest_row'] ?? 0);
        $reader = app(MaeprodSpreadsheetReader::class);

        if ($highestRow < 1 || ! isset($state['data_headers'])) {
            $metadata = $reader->readMetadata($sourcePath);
            $highestRow = (int) $metadata['highest_row'];
            $rawHeaders = $metadata['headers'];
            $columnCount = (int) $metadata['column_count'];

            if ($highestRow < 1 || $this->isEmptyCsvRow($rawHeaders)) {
                throw new \InvalidArgumentException('El archivo Excel no contiene encabezados válidos.');
            }

            $dataHeaders = array_values(array_filter($rawHeaders, fn (string $header) => $header !== ''));
            $headerRow = array_merge(['_csv_line'], $dataHeaders);
            $delimiter = ';';
        } else {
            $dataHeaders = $state['data_headers'];
            $headerRow = $state['header_row'];
            $delimiter = (string) ($state['delimiter'] ?? ';');
            $rawHeaders = $state['raw_headers'] ?? $dataHeaders;
            $columnCount = (int) ($state['column_count'] ?? count($rawHeaders));
        }

        if ($highestRow < 2) {
            throw new \InvalidArgumentException('El archivo no contiene filas de productos.');
        }

        if (! isset($state['data_headers'])) {
            $state['data_headers'] = $dataHeaders;
            $state['header_row'] = $headerRow;
            $state['delimiter'] = $delimiter;
            $state['raw_headers'] = $rawHeaders;
            $state['column_count'] = $columnCount;
        }

        $endRow = min($nextRow + self::EXCEL_ROWS_PER_PREPARE_REQUEST - 1, $highestRow);
        $rows = $reader->readDataRows(
            $sourcePath,
            $nextRow,
            $endRow,
            $rawHeaders,
            $columnCount,
        );

        $writer = new MaeprodImportBatchWriter(
            $jobDir,
            $headerRow,
            $delimiter,
            $rowsPerBatch,
            $processedRows,
        );

        foreach ($rows as $row) {
            $lineValues = [(string) ($row['_csv_line'] ?? '')];
            foreach ($dataHeaders as $header) {
                $lineValues[] = $row[$header] ?? '';
            }

            $writer->writeRow($lineValues);
        }

        $writer->finalize();

        $newProcessedRows = $writer->getRowsWritten();
        $newNextRow = $endRow + 1;
        $finished = $endRow >= $highestRow;

        if ($newProcessedRows === 0 && $finished) {
            throw new \InvalidArgumentException('El archivo no contiene filas de productos.');
        }

        return [
            'rows_written' => $newProcessedRows,
            'batch_count' => $writer->getBatchCount(),
            'next_row' => $newNextRow,
            'finished' => $finished,
            'data_headers' => $dataHeaders,
            'header_row' => $headerRow,
            'delimiter' => $delimiter,
            'highest_row' => $highestRow,
        ];
    }

    /**
     * @return array{data_headers: list<string>, header_row: list<string>, delimiter: string}
     */
    public function readCsvHeaders(string $sourcePath): array
    {
        $handle = fopen($sourcePath, 'rb');

        if ($handle === false) {
            throw new \RuntimeException('No se pudo abrir el archivo.');
        }

        try {
            return $this->readCsvHeaderFromHandle($handle);
        } finally {
            fclose($handle);
        }
    }

    public function countCsvDataRows(string $sourcePath, string $delimiter): int
    {
        $handle = fopen($sourcePath, 'rb');

        if ($handle === false) {
            return 0;
        }

        try {
            fgets($handle);
            $count = 0;

            while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
                if (! $this->isEmptyCsvRow($data)) {
                    $count++;
                }
            }

            return $count;
        } finally {
            fclose($handle);
        }
    }

    public function isSpreadsheetPath(string $path, ?string $originalName = null): bool
    {
        return MaeprodImportFileTypes::isSpreadsheet($originalName ?? basename($path));
    }

    /**
     * @param  resource  $handle
     * @return array{data_headers: list<string>, header_row: list<string>, delimiter: string}
     */
    private function readCsvHeaderFromHandle($handle): array
    {
        $firstLine = fgets($handle);

        if ($firstLine === false || trim($firstLine) === '') {
            throw new \InvalidArgumentException('El archivo no contiene encabezados válidos.');
        }

        $firstLine = $this->ensureUtf8($firstLine);
        $delimiter = str_contains($firstLine, ';') ? ';' : ',';
        $headers = array_map(
            fn (string $header) => mb_strtolower(trim($this->stripBom($header))),
            str_getcsv($this->stripBom($firstLine), $delimiter),
        );

        $dataHeaders = array_values(array_filter($headers, fn (string $header) => $header !== ''));

        return [
            'data_headers' => $dataHeaders,
            'header_row' => array_merge(['_csv_line'], $dataHeaders),
            'delimiter' => $delimiter,
        ];
    }

    /**
     * @param  resource  $handle
     */
    private function seekCsvDataRow($handle, string $delimiter, int $targetDataRow): void
    {
        rewind($handle);
        fgets($handle);

        $currentDataRow = 0;

        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($this->isEmptyCsvRow($data)) {
                continue;
            }

            $currentDataRow++;

            if ($currentDataRow >= $targetDataRow) {
                break;
            }
        }
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

    private function ensureUtf8(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $converted = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');

        return $converted === false ? $value : $converted;
    }

    private function stripBom(string $value): string
    {
        if (str_starts_with($value, "\xEF\xBB\xBF")) {
            return substr($value, 3);
        }

        return $value;
    }
}

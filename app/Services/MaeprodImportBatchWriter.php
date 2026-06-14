<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class MaeprodImportBatchWriter
{
    private $handle = null;

    private int $batchCount = 0;

    private int $rowsWritten = 0;

    /**
     * @param  list<string>  $headerRow
     */
    public function __construct(
        private readonly string $jobDir,
        private readonly array $headerRow,
        private readonly string $delimiter,
        private readonly int $rowsPerBatch,
        int $resumeRowsWritten = 0,
    ) {
        File::ensureDirectoryExists($this->jobDir);
        $this->rowsWritten = $resumeRowsWritten;

        if ($resumeRowsWritten === 0) {
            return;
        }

        $this->batchCount = (int) ceil($resumeRowsWritten / $this->rowsPerBatch);

        $remainder = $resumeRowsWritten % $this->rowsPerBatch;

        if ($remainder === 0) {
            return;
        }

        $batchIndex = $this->batchCount - 1;
        $batchPath = $this->batchPath($batchIndex);
        $this->handle = fopen($batchPath, 'ab');

        if ($this->handle === false) {
            throw new \RuntimeException('No se pudo reanudar el lote de importación.');
        }
    }

    /**
     * @param  list<string>  $lineValues
     */
    public function writeRow(array $lineValues): void
    {
        if ($this->rowsWritten % $this->rowsPerBatch === 0) {
            $this->closeBatch();
            $this->openNewBatch();
        }

        if ($this->handle === null) {
            throw new \RuntimeException('No se pudo escribir en el lote de importación.');
        }

        fputcsv($this->handle, $lineValues, $this->delimiter);
        $this->rowsWritten++;
    }

    public function finalize(): void
    {
        $this->closeBatch();
    }

    public function getBatchCount(): int
    {
        return $this->batchCount;
    }

    public function getRowsWritten(): int
    {
        return $this->rowsWritten;
    }

    private function openNewBatch(): void
    {
        $batchPath = $this->batchPath($this->batchCount);
        $this->handle = fopen($batchPath, 'wb');

        if ($this->handle === false) {
            throw new \RuntimeException('No se pudo crear un lote de importación.');
        }

        fwrite($this->handle, "\xEF\xBB\xBF");
        fputcsv($this->handle, $this->headerRow, $this->delimiter);
        $this->batchCount++;
    }

    private function closeBatch(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    private function batchPath(int $batchIndex): string
    {
        return $this->jobDir.'/batch-'.str_pad((string) $batchIndex, 6, '0', STR_PAD_LEFT).'.csv';
    }
}

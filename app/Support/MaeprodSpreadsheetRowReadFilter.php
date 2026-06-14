<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class MaeprodSpreadsheetRowReadFilter implements IReadFilter
{
    public function __construct(
        private readonly int $startRow,
        private readonly int $endRow,
    ) {}

    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        return $row >= $this->startRow && $row <= $this->endRow;
    }
}

<?php

namespace App\Support;

class MaeprodImportFileTypes
{
    /** @var list<string> */
    public const CSV_EXTENSIONS = ['csv', 'txt'];

    /** @var list<string> */
    public const SPREADSHEET_EXTENSIONS = ['xlsx', 'xls'];

    /** @var list<string> */
    public const ALLOWED_EXTENSIONS = ['csv', 'txt', 'xlsx', 'xls'];

    public static function extensionFromName(string $filename): string
    {
        return mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    public static function isAllowed(string $filename): bool
    {
        return in_array(self::extensionFromName($filename), self::ALLOWED_EXTENSIONS, true);
    }

    public static function isSpreadsheet(string $filename): bool
    {
        return in_array(self::extensionFromName($filename), self::SPREADSHEET_EXTENSIONS, true);
    }

    public static function isCsv(string $filename): bool
    {
        return in_array(self::extensionFromName($filename), self::CSV_EXTENSIONS, true);
    }
}

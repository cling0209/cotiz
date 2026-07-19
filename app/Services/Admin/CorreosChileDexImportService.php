<?php

namespace App\Services\Admin;

use App\Models\CorreosChileDexTarifa;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class CorreosChileDexImportService
{
    /** @var list<float> */
    public const TRAMOS_KG = [5.9, 10, 20, 50, 100, 200, 400, 600, 1000, 2000, 3500, 5000, 10000];

    /**
     * @return array{imported: int, skipped: int, archivo: string, tramos: list<string>}
     */
    public function importFromUpload(UploadedFile $file, ?User $user = null): array
    {
        $path = $file->getRealPath();
        if ($path === false || $path === '') {
            throw new RuntimeException('No se pudo leer el archivo subido.');
        }

        return $this->importFromPath($path, $file->getClientOriginalName(), $user);
    }

    /**
     * @return array{imported: int, skipped: int, archivo: string, tramos: list<string>}
     */
    public function importFromPath(string $path, ?string $originalName = null, ?User $user = null): array
    {
        $rows = $this->parseSpreadsheet($path);
        $archivo = $originalName ?: basename($path);
        $now = now();
        $userId = $user?->id;

        return DB::transaction(function () use ($rows, $archivo, $now, $userId) {
            CorreosChileDexTarifa::query()->delete();

            $imported = 0;
            $skipped = 0;
            $tramos = [];

            foreach ($rows as $row) {
                if ($row['destino'] === '' || $row['tarifas'] === []) {
                    $skipped++;
                    continue;
                }

                CorreosChileDexTarifa::query()->create([
                    'origen' => $row['origen'] !== '' ? $row['origen'] : 'SANTIAGO',
                    'destino' => $row['destino'],
                    'destino_key' => CorreosChileDexTarifa::normalizeDestinoKey($row['destino']),
                    'recargo_pct' => $row['recargo_pct'],
                    'tarifas' => $row['tarifas'],
                    'archivo_origen' => $archivo,
                    'imported_by' => $userId,
                    'imported_at' => $now,
                ]);

                $imported++;
                if ($tramos === []) {
                    $tramos = array_keys($row['tarifas']);
                }
            }

            if ($imported === 0) {
                throw new RuntimeException('No se encontraron filas de tarifa válidas en el Excel.');
            }

            return [
                'imported' => $imported,
                'skipped' => $skipped,
                'archivo' => $archivo,
                'tramos' => $tramos,
            ];
        });
    }

    /**
     * @return list<array{origen: string, destino: string, recargo_pct: int|null, tarifas: array<string, int>}>
     */
    public function parseSpreadsheet(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }
        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }

        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = (int) $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

        $headerRow = null;
        $map = null;

        for ($row = 1; $row <= min($highestRow, 30); $row++) {
            $values = $this->readRow($sheet, $row, $highestColumnIndex);
            $candidate = $this->detectHeaderMap($values);
            if ($candidate !== null) {
                $headerRow = $row;
                $map = $candidate;
                break;
            }
        }

        if ($headerRow === null || $map === null) {
            throw new RuntimeException(
                'No se encontró la fila de encabezados (ORIGEN, DESTINO y tramos de peso). Verifique que sea el Excel de tarifa DEX CorreosChile.'
            );
        }

        $parsed = [];

        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            $values = $this->readRow($sheet, $row, $highestColumnIndex);
            $destino = trim((string) ($values[$map['destino']] ?? ''));

            if ($destino === '') {
                continue;
            }

            if ($this->isNotesRow($destino) || $this->isNotesRow(trim((string) ($values[$map['origen']] ?? '')))) {
                break;
            }

            $origen = trim((string) ($values[$map['origen']] ?? ''));
            $recargoRaw = $map['recargo'] !== null
                ? trim((string) ($values[$map['recargo']] ?? ''))
                : '';

            $tarifas = [];
            foreach ($map['tramos'] as $colIndex => $tramoKey) {
                $precio = $this->parseMoney($values[$colIndex] ?? null);
                if ($precio !== null) {
                    $tarifas[$tramoKey] = $precio;
                }
            }

            if ($tarifas === []) {
                continue;
            }

            $parsed[] = [
                'origen' => $origen !== '' ? Str::upper($origen) : 'SANTIAGO',
                'destino' => $destino,
                'recargo_pct' => $this->parseRecargo($recargoRaw),
                'tarifas' => $tarifas,
            ];
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $parsed;
    }

    /**
     * @param  list<mixed>  $values
     * @return array{origen: int, destino: int, recargo: int|null, tramos: array<int, string>}|null
     */
    private function detectHeaderMap(array $values): ?array
    {
        $origen = null;
        $destino = null;
        $recargo = null;
        $tramos = [];

        foreach ($values as $colIndex => $raw) {
            $label = $this->normalizeHeaderLabel((string) ($raw ?? ''));
            if ($label === '') {
                continue;
            }

            if ($origen === null && ($label === 'ORIGEN' || $label === 'ORIGIN')) {
                $origen = $colIndex;
                continue;
            }

            if ($destino === null && ($label === 'DESTINO' || $label === 'DESTINATION' || $label === 'COMUNA')) {
                $destino = $colIndex;
                continue;
            }

            if ($recargo === null && (
                str_contains($label, 'RECARGO')
                || str_contains($label, 'ZONA RECARGO')
                || $label === 'ZONA'
            )) {
                $recargo = $colIndex;
                continue;
            }

            $tramo = $this->parseWeightHeader($label);
            if ($tramo !== null) {
                $tramos[$colIndex] = $this->formatTramoKey($tramo);
            }
        }

        if ($origen === null || $destino === null || $tramos === []) {
            return null;
        }

        return [
            'origen' => $origen,
            'destino' => $destino,
            'recargo' => $recargo,
            'tramos' => $tramos,
        ];
    }

    /**
     * @return list<mixed>
     */
    private function readRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row, int $highestColumnIndex): array
    {
        $values = [];
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $values[$col] = $sheet->getCell(Coordinate::stringFromColumnIndex($col).$row)->getCalculatedValue();
        }

        return $values;
    }

    private function normalizeHeaderLabel(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        $value = str_replace(["\xc2\xa0", "\xA0"], ' ', $value);
        $value = mb_strtoupper($value, 'UTF-8');

        return $value;
    }

    private function parseWeightHeader(string $label): ?float
    {
        $normalized = str_replace([' ', 'KG', 'KGS', 'KILOS'], '', $label);
        $normalized = str_replace(',', '.', $normalized);
        $normalized = preg_replace('/[^0-9.]/', '', $normalized) ?? '';

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        $weight = (float) $normalized;
        if ($weight <= 0) {
            return null;
        }

        return $weight;
    }

    private function formatTramoKey(float $kg): string
    {
        $formatted = rtrim(rtrim(number_format($kg, 3, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    private function parseRecargo(string $raw): ?int
    {
        $raw = trim(mb_strtoupper($raw, 'UTF-8'));
        if ($raw === '' || $raw === 'NO' || $raw === 'N' || $raw === '0' || $raw === '0%') {
            return null;
        }

        if (preg_match('/(\d+)/', $raw, $m)) {
            $pct = (int) $m[1];

            return $pct > 0 ? $pct : null;
        }

        return null;
    }

    private function parseMoney(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (int) round((float) $value);
        }

        $raw = trim((string) $value);
        $raw = str_replace(['$', ' ', "\xc2\xa0"], '', $raw);

        // Chilean thousands: 3.830 or 3.830,50
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $raw)) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } else {
            $raw = str_replace(',', '.', $raw);
        }

        $raw = preg_replace('/[^0-9.\-]/', '', $raw) ?? '';
        if ($raw === '' || ! is_numeric($raw)) {
            return null;
        }

        return (int) round((float) $raw);
    }

    private function isNotesRow(string $value): bool
    {
        $upper = mb_strtoupper(trim($value), 'UTF-8');

        return str_starts_with($upper, 'NOTA')
            || str_starts_with($upper, 'TARIFAS EXENTAS')
            || str_starts_with($upper, 'PESO MAXIMO')
            || str_starts_with($upper, 'EQUIVALENCIA')
            || str_starts_with($upper, 'DIMENSIONES');
    }
}

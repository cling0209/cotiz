<?php

namespace App\Services;

use App\Models\Maeprod;
use App\Support\MaeprodImportColumnMapping;
use App\Support\MaeprodImportError;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaeprodImportService
{
    public const UPSERT_CHUNK_SIZE = 500;
    /** @var array<string, string> */
    private const HEADER_ALIASES = [
        'codigo' => 'prod_item',
        'prod_item' => 'prod_item',
        'sku' => 'prod_item',
        'nombre' => 'prod_nombre',
        'prod_nombre' => 'prod_nombre',
        'descripcion' => 'prod_nombre',
        'familia' => 'prod_familia',
        'prod_familia' => 'prod_familia',
        'precio' => 'prod_valor',
        'prod_valor' => 'prod_valor',
        'costo' => 'prod_valor_costo',
        'prod_valor_costo' => 'prod_valor_costo',
        'nombre_archivo' => 'prod_imagen',
        'prod_imagen' => 'prod_imagen',
        'imagen' => 'prod_imagen',
        'gramaje' => 'prod_gramaje',
        'prod_gramaje' => 'prod_gramaje',
        'stock' => 'prod_stock_real',
        'prod_stock_real' => 'prod_stock_real',
        'softland' => 'prod_item_softland',
        'prod_item_softland' => 'prod_item_softland',
    ];

    /**
     * @return list<string>
     */
    public function templateHeaders(): array
    {
        return [
            'codigo',
            'nombre',
            'familia',
            'precio',
            'costo',
            'nombre_archivo',
            'gramaje',
            'stock',
            'softland',
        ];
    }

    public function templateCsvDownloadResponse(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $this->templateHeaders(), ';');
            fputcsv($handle, [
                'DEMO001',
                'PRODUCTO EJEMPLO PAPEL BOND',
                'PAPEL',
                '4500',
                '3600',
                'DEMO001_medium.jpg',
                '75 GR',
                '100',
                '',
            ], ';');

            fclose($handle);
        }, 'plantilla_productos_maeprod.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportCsvResponse(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $this->templateHeaders(), ';');

            Maeprod::query()
                ->orderBy('prod_familia')
                ->orderBy('prod_nombre')
                ->chunk(500, function ($productos) use ($handle) {
                    foreach ($productos as $producto) {
                        fputcsv($handle, [
                            $producto->prod_item,
                            $producto->prod_nombre,
                            $producto->prod_familia,
                            (int) ($producto->prod_valor ?? 0),
                            (int) ($producto->prod_valor_costo ?? 0),
                            $producto->prod_imagen ?? '',
                            $producto->prod_gramaje ?? '',
                            $producto->prod_stock_real ?? '',
                            $producto->prod_item_softland ?? '',
                        ], ';');
                    }
                });

            fclose($handle);
        }, 'maeprod_'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{created: int, updated: int, skipped: int, errors: list<array{fila: int|null, codigo: string, nombre: string, familia: string, mensaje: string, detalle: string|null}>}
     */
    public function emptyResult(): array
    {
        return [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];
    }

    /**
     * @param  list<array{fila: int|null, codigo: string, nombre: string, familia: string, mensaje: string, detalle: string|null}>  $errors
     */
    public function errorsCsvDownloadResponse(array $errors): StreamedResponse
    {
        return response()->streamDownload(function () use ($errors) {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['fila', 'codigo', 'nombre', 'familia', 'error', 'detalle'], ';');

            foreach ($errors as $error) {
                fputcsv($handle, [
                    $error['fila'] ?? '',
                    $error['codigo'] ?? '',
                    $error['nombre'] ?? '',
                    $error['familia'] ?? '',
                    $error['mensaje'] ?? '',
                    $error['detalle'] ?? '',
                ], ';');
            }

            fclose($handle);
        }, 'errores_importacion_maeprod_'.now()->format('Y-m-d_His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{created: int, updated: int, skipped: int, errors: list<array{fila: int|null, codigo: string, nombre: string, familia: string, mensaje: string, detalle: string|null}>}
     */
    public function importFromUploadedFile(UploadedFile $file, ?string $usuarioUpd = null, ?array $columnMapping = null): array
    {
        $rows = $this->parseCsv($file);

        if ($rows === []) {
            return array_merge($this->emptyResult(), [
                'errors' => [MaeprodImportError::general('El archivo no contiene filas de datos.')],
            ]);
        }

        return $this->importRows($rows, $usuarioUpd, $columnMapping);
    }

    /**
     * @return array{created: int, updated: int, skipped: int, errors: list<array{fila: int|null, codigo: string, nombre: string, familia: string, mensaje: string, detalle: string|null}>}
     */
    public function importFromPath(string $path, ?string $usuarioUpd = null, ?array $columnMapping = null): array
    {
        $rows = $this->parseCsvFromPath($path);

        if ($rows === []) {
            return array_merge($this->emptyResult(), [
                'errors' => [MaeprodImportError::general('El archivo no contiene filas de datos.')],
            ]);
        }

        return $this->importRows($rows, $usuarioUpd, $columnMapping);
    }

    /**
     * @param  array<string, string|null>  $columnMapping
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     summary: array{crear: int, actualizar: int, error: int},
     *     total_rows: int,
     *     preview_limit: int
     * }
     */
    public function previewFromPath(string $path, array $columnMapping, int $limit = 10): array
    {
        $rows = $this->parseCsvFromPath($path);

        return $this->previewRows($rows, $columnMapping, $limit);
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  array<string, string|null>|null  $columnMapping
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     summary: array{crear: int, actualizar: int, error: int},
     *     total_rows: int,
     *     preview_limit: int
     * }
     */
    public function previewRows(array $rows, ?array $columnMapping, int $limit = 10): array
    {
        $preview = [];
        $summary = ['crear' => 0, 'actualizar' => 0, 'error' => 0];
        $limit = max(1, min($limit, 50));

        foreach (array_slice($rows, 0, $limit) as $lineNumber => $rawRow) {
            $csvLine = (int) ($rawRow['_csv_line'] ?? ($lineNumber + 2));
            $row = $this->mapRow($rawRow, $columnMapping);
            $validation = $this->validateRow($row, $csvLine);

            if ($validation !== null) {
                $preview[] = [
                    'fila' => $csvLine,
                    'accion' => 'error',
                    'codigo' => $row['prod_item'] ?? '',
                    'nombre' => $row['prod_nombre'] ?? '',
                    'familia' => $row['prod_familia'] ?? '',
                    'precio' => $row['prod_valor'] ?? '',
                    'mensaje' => $validation['mensaje'],
                    'detalle' => $validation['detalle'],
                ];
                $summary['error']++;

                continue;
            }

            $codigo = trim((string) $row['prod_item']);
            $existe = Maeprod::query()->where('prod_item', $codigo)->exists();
            $accion = $existe ? 'actualizar' : 'crear';
            $summary[$accion]++;

            $preview[] = [
                'fila' => $csvLine,
                'accion' => $accion,
                'codigo' => $codigo,
                'nombre' => mb_strtoupper(trim((string) $row['prod_nombre'])),
                'familia' => trim((string) $row['prod_familia']),
                'precio' => (int) $row['prod_valor'],
                'costo' => $row['prod_valor_costo'] !== '' ? (int) $row['prod_valor_costo'] : null,
                'mensaje' => null,
                'detalle' => null,
            ];
        }

        return [
            'rows' => $preview,
            'summary' => $summary,
            'total_rows' => count($rows),
            'preview_limit' => $limit,
        ];
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  array<string, string|null>|null  $columnMapping
     * @return array{created: int, updated: int, skipped: int, errors: list<array{fila: int|null, codigo: string, nombre: string, familia: string, mensaje: string, detalle: string|null}>}
     */
    public function importRows(array $rows, ?string $usuarioUpd = null, ?array $columnMapping = null): array
    {
        $result = $this->emptyResult();

        $pending = [];

        foreach ($rows as $lineNumber => $rawRow) {
            $csvLine = (int) ($rawRow['_csv_line'] ?? ($lineNumber + 2));
            $row = $this->mapRow($rawRow, $columnMapping);

            $validation = $this->validateRow($row, $csvLine);
            if ($validation !== null) {
                $this->pushError($result, $validation);
                $result['skipped']++;

                continue;
            }

            $pending[] = $row;
        }

        $pending = $this->deduplicateRowsByItem($pending, $result);

        foreach (array_chunk($pending, self::UPSERT_CHUNK_SIZE) as $chunk) {
            $this->persistChunk($chunk, $usuarioUpd, $result);
        }

        return $result;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  array{created: int, updated: int, skipped: int, errors: list<string>}  $result
     * @return list<array<string, string>>
     */
    private function deduplicateRowsByItem(array $rows, array &$result): array
    {
        /** @var array<string, array<string, string>> $unique */
        $unique = [];
        $duplicates = 0;

        foreach ($rows as $row) {
            $item = trim((string) $row['prod_item']);

            if (isset($unique[$item])) {
                $duplicates++;
            }

            $unique[$item] = $row;
        }

        if ($duplicates > 0) {
            $this->pushError($result, MaeprodImportError::general(
                "{$duplicates} fila(s) duplicada(s) en el CSV; se usó la última ocurrencia de cada código.",
            ));
        }

        return array_values($unique);
    }

    /**
     * @param  list<array<string, string>>  $chunk
     * @param  array{created: int, updated: int, skipped: int, errors: list<string>}  $result
     */
    private function persistChunk(array $chunk, ?string $usuarioUpd, array &$result): void
    {
        $items = array_map(
            fn (array $row) => trim((string) $row['prod_item']),
            $chunk,
        );

        /** @var \Illuminate\Support\Collection<string, Maeprod> $existing */
        $existing = Maeprod::query()
            ->whereIn('prod_item', $items)
            ->get()
            ->keyBy('prod_item');

        $upsertRows = [];

        foreach ($chunk as $row) {
            $item = trim((string) $row['prod_item']);
            $producto = $existing->get($item);
            $atributos = $this->buildAttributes($row, $usuarioUpd, $producto);
            $record = array_merge(['prod_item' => $item], $atributos);

            if ($producto) {
                if (! array_key_exists('prod_valor_fecha', $atributos)) {
                    $record['prod_valor_fecha'] = $producto->prod_valor_fecha;
                }
                if (! array_key_exists('prod_item_softland_fecha', $atributos)) {
                    $record['prod_item_softland_fecha'] = $producto->prod_item_softland_fecha;
                }
                if (! array_key_exists('prod_user_upd', $atributos)) {
                    $record['prod_user_upd'] = $producto->prod_user_upd;
                }
                $result['updated']++;
            } else {
                $result['created']++;
            }

            $upsertRows[] = $this->normalizeUpsertRecord($record);
        }

        if ($upsertRows === []) {
            return;
        }

        try {
            DB::transaction(function () use ($upsertRows) {
                Maeprod::query()->upsert(
                    $upsertRows,
                    ['prod_item'],
                    [
                        'prod_nombre',
                        'prod_familia',
                        'prod_imagen',
                        'prod_gramaje',
                        'prod_item_softland',
                        'prod_valor',
                        'prod_valor_costo',
                        'prod_stock_real',
                        'prod_valor_fecha',
                        'prod_item_softland_fecha',
                        'prod_user_upd',
                    ],
                );
            });
        } catch (\Throwable $exception) {
            $codigos = implode(', ', array_slice($items, 0, 5));
            $extra = count($items) > 5 ? '…' : '';

            $this->pushError($result, MaeprodImportError::general(
                'Error al guardar un lote de productos en la base de datos.',
                codigo: $codigos.$extra,
            ));

            $this->pushError($result, MaeprodImportError::general(
                config('app.debug') ? $exception->getMessage() : 'Revise duplicados o datos inválidos en el lote.',
            ));

            throw $exception;
        }
    }

    /**
     * @param  array{created: int, updated: int, skipped: int, errors: list<array<string, mixed>>}  $result
     * @param  array{fila: int|null, codigo: string, nombre: string, familia: string, mensaje: string, detalle: string|null}  $error
     */
    private function pushError(array &$result, array $error): void
    {
        $result['errors'][] = $error;
    }

    /**
     * @param  array<string, string>  $row
     * @return array{fila: int|null, codigo: string, nombre: string, familia: string, mensaje: string, detalle: string|null}|null
     */
    private function validateRow(array $row, int $fila): ?array
    {
        if (trim((string) ($row['prod_item'] ?? '')) === '') {
            return MaeprodImportError::row($fila, $row, 'Falta código.');
        }

        if (trim((string) ($row['prod_nombre'] ?? '')) === '') {
            return MaeprodImportError::row($fila, $row, 'Falta nombre.');
        }

        if (trim((string) ($row['prod_familia'] ?? '')) === '') {
            return MaeprodImportError::row($fila, $row, 'Falta familia.');
        }

        if (! is_numeric($row['prod_valor'] ?? null)) {
            return MaeprodImportError::row(
                $fila,
                $row,
                'Precio inválido.',
                'valor: '.($row['prod_valor'] ?? ''),
            );
        }

        if (isset($row['prod_valor_costo']) && $row['prod_valor_costo'] !== '' && ! is_numeric($row['prod_valor_costo'])) {
            return MaeprodImportError::row(
                $fila,
                $row,
                'Costo inválido.',
                'costo: '.$row['prod_valor_costo'],
            );
        }

        if (isset($row['prod_stock_real']) && $row['prod_stock_real'] !== '' && ! is_numeric($row['prod_stock_real'])) {
            return MaeprodImportError::row(
                $fila,
                $row,
                'Stock inválido.',
                'stock: '.$row['prod_stock_real'],
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function normalizeUpsertRecord(array $record): array
    {
        return [
            'prod_item' => $record['prod_item'],
            'prod_nombre' => $record['prod_nombre'],
            'prod_familia' => $record['prod_familia'],
            'prod_imagen' => $record['prod_imagen'] ?? null,
            'prod_gramaje' => $record['prod_gramaje'] ?? null,
            'prod_item_softland' => $record['prod_item_softland'] ?? null,
            'prod_valor' => $record['prod_valor'],
            'prod_valor_costo' => $record['prod_valor_costo'] ?? 0,
            'prod_stock_real' => $record['prod_stock_real'] ?? null,
            'prod_valor_fecha' => $this->formatDateTime($record['prod_valor_fecha'] ?? null),
            'prod_item_softland_fecha' => $this->formatDateTime($record['prod_item_softland_fecha'] ?? null),
            'prod_user_upd' => $record['prod_user_upd'] ?? null,
        ];
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function buildAttributes(array $row, ?string $usuarioUpd, ?Maeprod $existente): array
    {
        $nuevoValor = (int) $row['prod_valor'];
        $nuevoCosto = $row['prod_valor_costo'] !== '' ? (int) $row['prod_valor_costo'] : 0;

        $atributos = [
            'prod_nombre' => mb_strtoupper(trim((string) $row['prod_nombre'])),
            'prod_familia' => trim((string) $row['prod_familia']),
            'prod_imagen' => $this->nullableString($row['prod_imagen'] ?? ''),
            'prod_gramaje' => $this->nullableString($row['prod_gramaje'] ?? ''),
            'prod_item_softland' => $this->nullableString($row['prod_item_softland'] ?? ''),
            'prod_valor' => $nuevoValor,
            'prod_valor_costo' => $nuevoCosto,
            'prod_stock_real' => $row['prod_stock_real'] !== '' ? (int) $row['prod_stock_real'] : null,
        ];

        $precioCambio = ! $existente
            || (int) ($existente->prod_valor ?? 0) !== $nuevoValor
            || (int) ($existente->prod_valor_costo ?? 0) !== $nuevoCosto;

        if ($precioCambio) {
            $atributos['prod_valor_fecha'] = now();
            $atributos['prod_user_upd'] = $usuarioUpd;
        }

        $softlandNuevo = (string) ($atributos['prod_item_softland'] ?? '');
        $softlandAnterior = (string) ($existente?->prod_item_softland ?? '');
        if ($softlandNuevo !== $softlandAnterior) {
            $atributos['prod_item_softland_fecha'] = now();
        }

        if (! $existente) {
            $atributos['prod_valor_fecha'] = now();
            $atributos['prod_user_upd'] = $usuarioUpd;
        }

        return $atributos;
    }

    /**
     * @param  array<string, string>  $rawRow
     * @param  array<string, string|null>|null  $columnMapping
     * @return array<string, string>
     */
    private function mapRow(array $rawRow, ?array $columnMapping = null): array
    {
        if ($columnMapping !== null) {
            return $this->mapRowWithColumnMapping($rawRow, $columnMapping);
        }

        $mapped = [];

        foreach ($rawRow as $header => $value) {
            if ($header === '_csv_line') {
                continue;
            }

            $key = self::HEADER_ALIASES[mb_strtolower(trim($header))] ?? null;
            if ($key !== null) {
                $mapped[$key] = trim((string) $value);
            }
        }

        return $this->finalizeMappedRow($mapped);
    }

    /**
     * @param  array<string, string>  $rawRow
     * @param  array<string, string|null>  $columnMapping
     * @return array<string, string>
     */
    private function mapRowWithColumnMapping(array $rawRow, array $columnMapping): array
    {
        $mapped = [];

        foreach (MaeprodImportColumnMapping::FIELDS as $field => $meta) {
            $source = trim((string) ($columnMapping[$field] ?? ''));

            if ($source === '') {
                continue;
            }

            $mapped[$meta['key']] = trim((string) ($rawRow[$source] ?? ''));
        }

        return $this->finalizeMappedRow($mapped);
    }

    /**
     * @param  array<string, string>  $mapped
     * @return array<string, string>
     */
    private function finalizeMappedRow(array $mapped): array
    {
        foreach (['prod_valor_costo', 'prod_imagen', 'prod_gramaje', 'prod_stock_real', 'prod_item_softland'] as $optional) {
            $mapped[$optional] ??= '';
        }

        $mapped['prod_valor'] = $this->normalizeNumericField($mapped['prod_valor'] ?? '');
        $mapped['prod_valor_costo'] = $this->normalizeNumericField($mapped['prod_valor_costo'] ?? '');
        $mapped['prod_stock_real'] = $this->normalizeNumericField($mapped['prod_stock_real'] ?? '');

        return $mapped;
    }

    private function normalizeNumericField(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = str_replace([' ', "\xc2\xa0"], '', $value);

        if (preg_match('/,\d{1,2}$/', $value)) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);

            return $value;
        }

        if (str_contains($value, '.') && ! str_contains($value, ',')) {
            $parts = explode('.', $value);
            if (count($parts) > 1 && strlen(end($parts)) === 3) {
                return str_replace('.', '', $value);
            }
        }

        return $value;
    }

    public function readPathAsUtf8(string $path): string
    {
        $raw = file_get_contents($path);

        if ($raw === false) {
            return '';
        }

        return $this->ensureUtf8($raw);
    }

    /**
     * @return list<array<string, string>>
     */
    public function parseCsvText(string $content): array
    {
        return $this->parseCsvContent($content);
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseCsvFromPath(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $content = $this->readPathAsUtf8($path);

        return $this->parseCsvContent($content);
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseCsv(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if ($path === false) {
            return [];
        }

        $content = $this->readPathAsUtf8($path);
        if ($content === '') {
            return [];
        }

        return $this->parseCsvContent($content);
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseCsvContent(string $content): array
    {
        if ($content === '') {
            return [];
        }

        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        if ($lines === []) {
            return [];
        }

        $firstLine = (string) ($lines[0] ?? '');
        if (trim($firstLine) === '') {
            return [];
        }

        $delimiter = str_contains($firstLine, ';') ? ';' : ',';
        $headers = array_map(
            fn (string $h) => mb_strtolower(trim($this->stripBom($h))),
            str_getcsv($this->stripBom($firstLine), $delimiter),
        );

        $rows = [];

        foreach ($lines as $index => $line) {
            $physicalLine = $index + 1;

            if ($physicalLine === 1) {
                continue;
            }

            if (trim($line) === '') {
                continue;
            }

            $data = str_getcsv($line, $delimiter);
            if ($this->isEmptyRow($data)) {
                continue;
            }

            $row = ['_csv_line' => $physicalLine];
            foreach ($headers as $headerIndex => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = trim((string) ($data[$headerIndex] ?? ''));
            }

            if (count($row) > 1) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function nullableString(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function stripBom(string $value): string
    {
        if (str_starts_with($value, "\xEF\xBB\xBF")) {
            return substr($value, 3);
        }

        return $value;
    }

    private function ensureUtf8(string $value): string
    {
        $value = $this->stripBom($value);
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        foreach (['Windows-1252', 'ISO-8859-1'] as $encoding) {
            $converted = mb_convert_encoding($value, 'UTF-8', $encoding);
            if (mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }

        return $value;
    }

    /**
     * @param  list<string|null>  $data
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
}

<?php

namespace App\Services;

use App\Models\Maeprod;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaeprodImportService
{
    public const UPSERT_CHUNK_SIZE = 500;

    private const MAX_ERRORS = 100;
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
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    public function importFromUploadedFile(UploadedFile $file, ?string $usuarioUpd = null): array
    {
        $rows = $this->parseCsv($file);

        if ($rows === []) {
            return [
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => ['El archivo no contiene filas de datos.'],
            ];
        }

        return $this->importRows($rows, $usuarioUpd);
    }

    /**
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    public function importFromPath(string $path, ?string $usuarioUpd = null): array
    {
        $rows = $this->parseCsvFromPath($path);

        if ($rows === []) {
            return [
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => ['El archivo no contiene filas de datos.'],
            ];
        }

        return $this->importRows($rows, $usuarioUpd);
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    public function importRows(array $rows, ?string $usuarioUpd = null): array
    {
        $result = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        $pending = [];

        foreach ($rows as $lineNumber => $rawRow) {
            $row = $this->mapRow($rawRow);
            $fila = $lineNumber + 2;

            $validation = $this->validateRow($row, $fila);
            if ($validation !== null) {
                if (count($result['errors']) < self::MAX_ERRORS) {
                    $result['errors'][] = $validation;
                }
                $result['skipped']++;

                continue;
            }

            $pending[] = $row;
        }

        foreach (array_chunk($pending, self::UPSERT_CHUNK_SIZE) as $chunk) {
            $this->persistChunk($chunk, $usuarioUpd, $result);
        }

        return $result;
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

            $upsertRows[] = $record;
        }

        if ($upsertRows === []) {
            return;
        }

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
    }

    /**
     * @param  array<string, string>  $row
     */
    private function validateRow(array $row, int $fila): ?string
    {
        if (trim((string) ($row['prod_item'] ?? '')) === '') {
            return "Fila {$fila}: falta codigo.";
        }

        if (trim((string) ($row['prod_nombre'] ?? '')) === '') {
            return "Fila {$fila}: falta nombre.";
        }

        if (trim((string) ($row['prod_familia'] ?? '')) === '') {
            return "Fila {$fila}: falta familia.";
        }

        if (! is_numeric($row['prod_valor'] ?? null)) {
            return "Fila {$fila}: precio inválido.";
        }

        if (isset($row['prod_valor_costo']) && $row['prod_valor_costo'] !== '' && ! is_numeric($row['prod_valor_costo'])) {
            return "Fila {$fila}: costo inválido.";
        }

        if (isset($row['prod_stock_real']) && $row['prod_stock_real'] !== '' && ! is_numeric($row['prod_stock_real'])) {
            return "Fila {$fila}: stock inválido.";
        }

        return null;
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
     * @return array<string, string>
     */
    private function mapRow(array $rawRow): array
    {
        $mapped = [];

        foreach ($rawRow as $header => $value) {
            $key = self::HEADER_ALIASES[mb_strtolower(trim($header))] ?? null;
            if ($key !== null) {
                $mapped[$key] = trim((string) $value);
            }
        }

        foreach (['prod_valor_costo', 'prod_imagen', 'prod_gramaje', 'prod_stock_real', 'prod_item_softland'] as $optional) {
            $mapped[$optional] ??= '';
        }

        return $mapped;
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

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return [];
        }

        fwrite($handle, $content);
        rewind($handle);

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);

            return [];
        }

        $delimiter = str_contains($firstLine, ';') ? ';' : ',';
        $headers = array_map(
            fn (string $h) => mb_strtolower(trim($this->stripBom($h))),
            str_getcsv($this->stripBom($firstLine), $delimiter),
        );

        $rows = [];

        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($this->isEmptyRow($data)) {
                continue;
            }

            $row = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $row[$header] = trim((string) ($data[$index] ?? ''));
            }

            if ($row !== []) {
                $rows[] = $row;
            }
        }

        fclose($handle);

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

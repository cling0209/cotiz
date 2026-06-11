<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImportService
{
    private const PERSIST_CHUNK_SIZE = 50;

    /** @var array<string, int> */
    private array $categoryIdByKey = [];

    /** @var array<string, Product> */
    private array $existingProductsBySku = [];

    /** @var array<string, int|string> slug => product id or temporary owner key */
    private array $reservedSlugs = [];

    /**
     * @return list<string>
     */
    public function templateHeaders(): array
    {
        return [
            'sku',
            'nombre',
            'precio',
            'stock',
            'familia',
            'slug',
            'descripcion',
            'precio_referencia',
            'peso_kg',
            'activo',
            'destacado',
            'nombre_archivo',
        ];
    }

    public function parseUploadedCsv(UploadedFile $file): array
    {
        return $this->parseCsv($file);
    }

    public function exportProductsCsvResponse(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $this->templateHeaders(), ';');

            Product::query()
                ->with('category')
                ->orderBy('sku')
                ->chunk(500, function ($products) use ($handle) {
                    foreach ($products as $product) {
                        fputcsv($handle, [
                            $product->sku,
                            $product->name,
                            number_format((float) $product->price, 0, '', ''),
                            $product->stock,
                            $product->familia ?? '',
                            $product->slug,
                            $product->description ?? '',
                            $product->compare_at_price !== null
                                ? number_format((float) $product->compare_at_price, 0, '', '')
                                : '',
                            $product->weight_kg !== null
                                ? rtrim(rtrim(number_format((float) $product->weight_kg, 3, '.', ''), '0'), '.')
                                : '',
                            $product->is_active ? '1' : '0',
                            $product->is_featured ? '1' : '0',
                            $product->image_filename ?? '',
                        ], ';');
                    }
                });

            fclose($handle);
        }, 'productos_'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function generateTemplateCsv(): string
    {
        $handle = fopen('php://temp', 'r+');

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $this->templateHeaders(), ';');
        fputcsv($handle, [
            'AUD-001',
            'Audífonos Bluetooth Pro',
            '29990',
            '45',
            'LIB',
            'audifonos-bluetooth-pro',
            'Descripción opcional del producto',
            '39990',
            '0.35',
            '1',
            '1',
            '90503_medium.jpg',
        ], ';');

        rewind($handle);
        $content = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $content;
    }

    /**
     * @return list<array<string, string>>
     */
    public function parseCsvFromPath(string $path): array
    {
        if (! is_readable($path)) {
            return [];
        }

        $file = new UploadedFile($path, basename($path), 'text/csv', null, true);

        return $this->parseCsv($file);
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{
     *     staging: list<array<string, mixed>>,
     *     created: int,
     *     updated: int,
     *     reactivated: int,
     *     skipped: int,
     *     errors: list<string>
     * }
     */
    public function prepareBulkImport(array $rows, ?ProductImportProgress $progress = null): array
    {
        $totalRows = count($rows);
        $progress?->phase('Cargando categorías, SKUs y slugs en memoria...');
        $this->warmImportCaches($rows);

        $staging = [];
        $errors = [];
        $skipped = 0;
        $created = 0;
        $updated = 0;
        $reactivated = 0;

        $progress?->progressStart($totalRows, 'Validando filas');
        $processed = 0;

        foreach ($rows as $lineNumber => $row) {
            $outcome = $this->prepareRow($row, $lineNumber);
            $processed++;
            $progress?->progressAdvance($processed);

            if ($outcome['error'] !== null) {
                $errors[] = $outcome['error'];
                $skipped++;

                continue;
            }

            $staging[] = $outcome['payload'];

            match ($outcome['action']) {
                'created' => $created++,
                'updated' => $updated++,
                'reactivated' => $reactivated++,
                default => null,
            };
        }

        $progress?->progressFinish();
        $this->resetImportCaches();

        return [
            'staging' => $staging,
            'created' => $created,
            'updated' => $updated,
            'reactivated' => $reactivated,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * @return array{created: int, updated: int, reactivated: int, skipped: int, errors: list<string>}
     */
    public function importFromUploadedFile(UploadedFile $file): array
    {
        $rows = $this->parseCsv($file);

        if ($rows === []) {
            return [
                'created' => 0,
                'updated' => 0,
                'reactivated' => 0,
                'skipped' => 0,
                'errors' => ['El archivo no contiene filas de datos.'],
            ];
        }

        $this->warmImportCaches($rows);

        $result = [
            'created' => 0,
            'updated' => 0,
            'reactivated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        $inserts = [];
        $updates = [];
        $reactivates = [];

        foreach ($rows as $lineNumber => $row) {
            $outcome = $this->prepareRow($row, $lineNumber);

            if ($outcome['error'] !== null) {
                $result['errors'][] = $outcome['error'];
                $result['skipped']++;

                continue;
            }

            match ($outcome['action']) {
                'created' => $inserts[] = $outcome['payload'],
                'updated' => $updates[] = $outcome['payload'],
                'reactivated' => $reactivates[] = $outcome['payload'],
                default => null,
            };

            if (count($inserts) + count($updates) + count($reactivates) >= self::PERSIST_CHUNK_SIZE) {
                $this->flushPersistBuffers($inserts, $updates, $reactivates, $result);
            }
        }

        $this->flushPersistBuffers($inserts, $updates, $reactivates, $result);
        $this->resetImportCaches();

        return $result;
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    protected function warmImportCaches(array $rows): void
    {
        $this->categoryIdByKey = [];
        Category::query()
            ->whereNull('deleted_at')
            ->get(['id', 'slug'])
            ->each(function (Category $category): void {
                $this->categoryIdByKey[$category->slug] = $category->id;
                $this->categoryIdByKey[Str::lower($category->slug)] = $category->id;
            });

        $skus = [];

        foreach ($rows as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));

            if ($sku !== '') {
                $skus[$sku] = true;
            }
        }

        $this->existingProductsBySku = [];

        if ($skus !== []) {
            Product::withTrashed()
                ->whereIn('sku', array_keys($skus))
                ->get()
                ->each(function (Product $product): void {
                    $this->existingProductsBySku[$product->sku] = $product;
                });
        }

        $this->reservedSlugs = Product::query()
            ->whereNull('deleted_at')
            ->pluck('id', 'slug')
            ->all();
    }

    protected function resetImportCaches(): void
    {
        $this->categoryIdByKey = [];
        $this->existingProductsBySku = [];
        $this->reservedSlugs = [];
    }

    /**
     * @param  list<array<string, mixed>>  $inserts
     * @param  list<array<string, mixed>>  $updates
     * @param  list<array<string, mixed>>  $reactivates
     * @param  array{created: int, updated: int, reactivated: int, skipped: int, errors: list<string>}  $result
     */
    protected function flushPersistBuffers(array &$inserts, array &$updates, array &$reactivates, array &$result): void
    {
        if ($inserts === [] && $updates === [] && $reactivates === []) {
            return;
        }

        try {
            DB::transaction(function () use (&$inserts, &$updates, &$reactivates, &$result): void {
                $this->persistInserts($inserts, $result);
                $this->persistUpdates($updates, $result, reactivate: false);
                $this->persistUpdates($reactivates, $result, reactivate: true);
            });
        } catch (QueryException $exception) {
            $this->persistBuffersIndividually($inserts, $updates, $reactivates, $result, $exception);
        }

        $inserts = [];
        $updates = [];
        $reactivates = [];
    }

    /**
     * @param  list<array<string, mixed>>  $inserts
     * @param  array{created: int, updated: int, reactivated: int, skipped: int, errors: list<string>}  $result
     */
    protected function persistInserts(array $inserts, array &$result): void
    {
        if ($inserts === []) {
            return;
        }

        $now = now();

        foreach ($inserts as &$row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }

        unset($row);

        Product::query()->insert($inserts);
        $result['created'] += count($inserts);
    }

    /**
     * @param  list<array<string, mixed>>  $updates
     * @param  array{created: int, updated: int, reactivated: int, skipped: int, errors: list<string>}  $result
     */
    protected function persistUpdates(array $updates, array &$result, bool $reactivate): void
    {
        if ($updates === []) {
            return;
        }

        $now = now();
        $columns = [
            'category_id',
            'sku',
            'familia',
            'image_filename',
            'name',
            'slug',
            'description',
            'price',
            'compare_at_price',
            'stock',
            'weight_kg',
            'is_active',
            'is_featured',
            'updated_at',
        ];

        if ($reactivate) {
            $columns[] = 'deleted_at';
        }

        foreach ($updates as &$row) {
            $row['updated_at'] = $now;

            if ($reactivate) {
                $row['deleted_at'] = null;
            }
        }

        unset($row);

        Product::query()->upsert($updates, ['id'], $columns);
        $result[$reactivate ? 'reactivated' : 'updated'] += count($updates);
    }

    /**
     * @param  list<array<string, mixed>>  $inserts
     * @param  list<array<string, mixed>>  $updates
     * @param  list<array<string, mixed>>  $reactivates
     * @param  array{created: int, updated: int, reactivated: int, skipped: int, errors: list<string>}  $result
     */
    protected function persistBuffersIndividually(
        array $inserts,
        array $updates,
        array $reactivates,
        array &$result,
        QueryException $exception,
    ): void {
        $fallbackError = $this->friendlyDbError($exception);

        foreach ($inserts as $payload) {
            $outcome = $this->persistProduct(new Product, $payload, 0, 'created');

            if ($outcome['error'] !== null) {
                $result['errors'][] = 'SKU '.$payload['sku'].': '.$outcome['error'];
                $result['skipped']++;
            } else {
                $result['created']++;
            }
        }

        foreach ($updates as $payload) {
            $product = $this->existingProductsBySku[$payload['sku']] ?? Product::query()->find($payload['id']);

            if (! $product) {
                $result['errors'][] = 'SKU '.$payload['sku'].': '.$fallbackError;
                $result['skipped']++;

                continue;
            }

            $outcome = $this->persistProduct($product, $payload, 0, 'updated');

            if ($outcome['error'] !== null) {
                $result['errors'][] = 'SKU '.$payload['sku'].': '.$outcome['error'];
                $result['skipped']++;
            } else {
                $result['updated']++;
            }
        }

        foreach ($reactivates as $payload) {
            $product = $this->existingProductsBySku[$payload['sku']] ?? Product::withTrashed()->find($payload['id']);

            if (! $product) {
                $result['errors'][] = 'SKU '.$payload['sku'].': '.$fallbackError;
                $result['skipped']++;

                continue;
            }

            $outcome = $this->persistProduct($product, $payload, 0, 'reactivated', reactivate: true);

            if ($outcome['error'] !== null) {
                $result['errors'][] = 'SKU '.$payload['sku'].': '.$outcome['error'];
                $result['skipped']++;
            } else {
                $result['reactivated']++;
            }
        }
    }

    /**
     * @return list<array<string, string>>
     */
    protected function parseCsv(UploadedFile $file): array
    {
        $content = $this->readFileAsUtf8($file);

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
        $headers = $this->normalizeHeaders(str_getcsv($firstLine, $delimiter));
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

                $row[$header] = $this->ensureUtf8(trim((string) ($data[$index] ?? '')));
            }

            if ($row !== []) {
                $rows[] = $row;
            }
        }

        fclose($handle);

        return $rows;
    }

    public function readPathAsUtf8(string $path): string
    {
        $raw = file_get_contents($path);

        if ($raw === false) {
            return '';
        }

        return $this->ensureUtf8($raw);
    }

    protected function readFileAsUtf8(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        if ($path === false) {
            return '';
        }

        return $this->readPathAsUtf8($path);
    }

    protected function ensureUtf8(string $value): string
    {
        $value = $this->stripBom($value);

        if ($value === '') {
            return '';
        }

        if ($this->isValidUtf8($value)) {
            return $value;
        }

        foreach (['Windows-1252', 'ISO-8859-1', 'CP1252'] as $encoding) {
            $converted = mb_convert_encoding($value, 'UTF-8', $encoding);

            if (mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }

        $converted = iconv('UTF-8', 'UTF-8//IGNORE', $value);

        return $converted !== false ? $converted : $value;
    }

    protected function isValidUtf8(string $value): bool
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return false;
        }

        return preg_match('//u', $value) === 1;
    }

    /**
     * @param  list<string|null>  $data
     */
    protected function isEmptyRow(array $data): bool
    {
        foreach ($data as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    protected function stripBom(string $line): string
    {
        if (str_starts_with($line, "\xEF\xBB\xBF")) {
            return substr($line, 3);
        }

        return $line;
    }

    /**
     * @param  list<string|null>  $headers
     * @return list<string>
     */
    protected function normalizeHeaders(array $headers): array
    {
        $aliases = [
            'name' => 'nombre',
            'price' => 'precio',
            'description' => 'descripcion',
            'compare_at_price' => 'precio_referencia',
            'weight_kg' => 'peso_kg',
            'is_active' => 'activo',
            'is_featured' => 'destacado',
            'image_filename' => 'nombre_archivo',
            'archivo_imagen' => 'nombre_archivo',
        ];

        return array_map(function (?string $header) use ($aliases) {
            $normalized = Str::lower(trim((string) $header));
            $normalized = str_replace([' ', '-'], '_', $normalized);

            return $aliases[$normalized] ?? $normalized;
        }, $headers);
    }

    /**
     * @param  array<string, string>  $row
     * @return array{action: string, error: string|null, payload?: array<string, mixed>}
     */
    protected function prepareRow(array $row, int $lineNumber): array
    {
        $displayLine = $lineNumber + 2;

        $validator = Validator::make($row, [
            'sku' => ['required', 'string', 'max:60'],
            'nombre' => ['required', 'string', 'max:200'],
            'precio' => ['required', 'numeric', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'familia' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:200'],
            'descripcion' => ['nullable', 'string'],
            'precio_referencia' => ['nullable', 'numeric', 'min:0'],
            'peso_kg' => ['nullable', 'numeric', 'min:0'],
            'activo' => ['nullable'],
            'destacado' => ['nullable'],
            'nombre_archivo' => ['nullable', 'string', 'max:255'],
        ], [], [
            'sku' => 'sku',
            'nombre' => 'nombre',
            'precio' => 'precio',
            'stock' => 'stock',
            'familia' => 'familia',
        ]);

        if ($validator->fails()) {
            return [
                'action' => 'skipped',
                'error' => 'Fila '.$displayLine.': '.$validator->errors()->first(),
            ];
        }

        $validated = $validator->validated();
        $familia = trim($validated['familia']);
        $categoryId = $this->resolveCategoryIdFromFamilia($familia);

        if ($categoryId === null) {
            return [
                'action' => 'skipped',
                'error' => 'Fila '.$displayLine.': no existe categoría para la familia "'.$familia.'".',
            ];
        }

        $payload = [
            'category_id' => $categoryId,
            'sku' => $validated['sku'],
            'name' => $validated['nombre'],
            'description' => $validated['descripcion'] ?? null,
            'price' => $validated['precio'],
            'compare_at_price' => $this->nullableNumeric($validated['precio_referencia'] ?? null),
            'stock' => (int) $validated['stock'],
            'weight_kg' => $this->nullableNumeric($validated['peso_kg'] ?? null),
            'is_active' => $this->parseBoolean($validated['activo'] ?? null, true),
            'is_featured' => $this->parseBoolean($validated['destacado'] ?? null, false),
            'familia' => $familia,
            'image_filename' => $validated['nombre_archivo'] ?? null,
        ];

        $existing = $this->existingProductsBySku[$payload['sku']] ?? null;

        if ($existing && ! $existing->trashed()) {
            $slug = trim((string) ($validated['slug'] ?? $existing->slug));

            if ($slug === '') {
                return [
                    'action' => 'skipped',
                    'error' => 'Fila '.$displayLine.': el slug es obligatorio.',
                ];
            }

            $payload['slug'] = $slug === $existing->slug
                ? $existing->slug
                : $this->reserveUniqueSlug($slug, $existing->id, $payload['sku']);
            $payload['id'] = $existing->id;

            return ['action' => 'updated', 'error' => null, 'payload' => $payload];
        }

        if ($existing && $existing->trashed()) {
            $payload['slug'] = $this->reserveUniqueSlug(
                $validated['slug'] ?? $this->defaultSlug($payload['name'], $payload['sku']),
                $existing->id,
                $payload['sku']
            );
            $payload['id'] = $existing->id;

            return ['action' => 'reactivated', 'error' => null, 'payload' => $payload];
        }

        $payload['slug'] = $this->reserveUniqueSlug(
            $validated['slug'] ?? $this->defaultSlug($payload['name'], $payload['sku']),
            sku: $payload['sku']
        );

        return ['action' => 'created', 'error' => null, 'payload' => $payload];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, error: string|null}
     */
    protected function persistProduct(
        Product $product,
        array $payload,
        int $displayLine,
        string $action,
        bool $reactivate = false,
    ): array {
        try {
            if ($reactivate) {
                $product->restore();
            }

            if ($product->exists) {
                $product->update($payload);
            } else {
                $product->fill($payload);
                $product->save();
            }
        } catch (QueryException $e) {
            $message = $displayLine > 0
                ? 'Fila '.$displayLine.': '.$this->friendlyDbError($e)
                : $this->friendlyDbError($e);

            return [
                'action' => 'skipped',
                'error' => $message,
            ];
        }

        return ['action' => $action, 'error' => null];
    }

    protected function friendlyDbError(QueryException $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'invalid byte sequence for encoding "UTF8"')) {
            return 'texto con caracteres inválidos (guarda el CSV en UTF-8 o Latin-1/Windows).';
        }

        return 'error al guardar en la base de datos.';
    }

    protected function defaultSlug(string $name, string $sku): string
    {
        return Str::slug($name) ?: Str::slug($sku) ?: 'producto';
    }

    protected function resolveCategoryIdFromFamilia(string $familia): ?int
    {
        foreach (array_unique([$familia, Str::lower($familia), Str::slug($familia)]) as $candidate) {
            if (isset($this->categoryIdByKey[$candidate])) {
                return $this->categoryIdByKey[$candidate];
            }
        }

        return null;
    }

    protected function nullableNumeric(?string $value): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return (float) $value;
    }

    protected function parseBoolean(?string $value, bool $default): bool
    {
        if ($value === null || trim($value) === '') {
            return $default;
        }

        $normalized = Str::lower(trim($value));

        return in_array($normalized, ['1', 'true', 'si', 'sí', 'yes', 'activo', 'on'], true);
    }

    protected function reserveUniqueSlug(string $slug, ?int $exceptId = null, ?string $sku = null): string
    {
        $base = Str::slug($slug) ?: ($sku ? Str::slug($sku) : '') ?: 'producto';
        $candidate = $base;
        $i = 1;

        while ($this->slugTaken($candidate, $exceptId)) {
            $candidate = $base.'-'.$i;
            $i++;
        }

        $this->reservedSlugs[$candidate] = $exceptId ?? ('new:'.$sku);

        return $candidate;
    }

    protected function slugTaken(string $slug, ?int $exceptId): bool
    {
        if (! isset($this->reservedSlugs[$slug])) {
            return false;
        }

        $owner = $this->reservedSlugs[$slug];

        return ! ($exceptId !== null && $owner === $exceptId);
    }
}

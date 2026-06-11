<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use PgSql\Connection;

class ProductBulkCopyImportService
{
    public function __construct(
        private readonly ProductImportService $importService,
    ) {}

    /**
     * @return array{created: int, updated: int, reactivated: int, skipped: int, errors: list<string>}
     */
    public function importFromPath(string $path, bool $dryRun = false, ?ProductImportProgress $progress = null): array
    {
        $progress?->phase('Leyendo archivo CSV...');
        $rows = $this->importService->parseCsvFromPath($path);

        if ($rows === []) {
            return [
                'created' => 0,
                'updated' => 0,
                'reactivated' => 0,
                'skipped' => 0,
                'errors' => ['El archivo no contiene filas de datos.'],
            ];
        }

        $progress?->phase('Validando '.number_format(count($rows), 0, '', '.').' filas...');
        $prepared = $this->importService->prepareBulkImport($rows, $progress);
        $staging = $this->dedupeStagingBySku($prepared['staging']);

        $result = [
            'created' => $prepared['created'],
            'updated' => $prepared['updated'],
            'reactivated' => $prepared['reactivated'],
            'skipped' => $prepared['skipped'],
            'errors' => $prepared['errors'],
        ];

        if ($dryRun) {
            $progress?->phase('Dry-run: no se escribirá en la base de datos.');

            return $result;
        }

        if ($staging === []) {
            $progress?->phase('No hay filas válidas para importar.');

            return $result;
        }

        if ($this->defaultDriver() !== 'pgsql') {
            throw new \RuntimeException('La importación masiva COPY requiere PostgreSQL.');
        }

        $progress?->phase('Importando '.number_format(count($staging), 0, '', '.').' productos a la base de datos...');

        if (extension_loaded('pgsql')) {
            $this->importViaPgsqlCopy($staging, $progress);
        } else {
            $this->importViaLaravelStaging($staging, $progress);
        }

        $progress?->phase('Importación en base de datos completada.');

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $staging
     * @return list<array<string, mixed>>
     */
    protected function dedupeStagingBySku(array $staging): array
    {
        $bySku = [];

        foreach ($staging as $row) {
            $bySku[(string) $row['sku']] = $row;
        }

        return array_values($bySku);
    }

    /**
     * @param  list<array<string, mixed>>  $staging
     */
    protected function importViaPgsqlCopy(array $staging, ?ProductImportProgress $progress = null): void
    {
        $progress?->phase('Conectando a PostgreSQL...');
        $connection = $this->connectPgsql();

        try {
            $this->assertPgResult(pg_query($connection, 'BEGIN'), $connection);
            $progress?->phase('Creando tabla staging temporal...');
            $this->createStagingTable($connection);
            $progress?->phase('COPY masivo a staging...');
            $this->copyIntoStaging($connection, $staging);
            $this->runMergeStatements($connection, $progress);
            $progress?->phase('Confirmando transacción...');
            $this->assertPgResult(pg_query($connection, 'COMMIT'), $connection);
        } catch (\Throwable $exception) {
            @pg_query($connection, 'ROLLBACK');

            throw $exception;
        } finally {
            pg_close($connection);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $staging
     */
    protected function importViaLaravelStaging(array $staging, ?ProductImportProgress $progress = null): void
    {
        $chunks = array_chunk($this->formatStagingForDatabase($staging), 500);
        $totalChunks = max(1, count($chunks));

        DB::transaction(function () use ($chunks, $totalChunks, $progress): void {
            $progress?->phase('Creando tabla staging temporal...');
            DB::unprepared($this->createStagingTableSql());

            $progress?->progressStart($totalChunks, 'Cargando staging');
            foreach ($chunks as $index => $chunk) {
                DB::table('product_import_staging')->insert($chunk);
                $progress?->progressAdvance($index + 1);
            }
            $progress?->progressFinish();

            $this->runMergeStatementsViaLaravel($progress);
        });
    }

    protected function connectPgsql(): Connection
    {
        $config = config('database.connections.'.config('database.default'));

        if (($config['driver'] ?? null) !== 'pgsql') {
            throw new \RuntimeException('La importación masiva COPY requiere PostgreSQL.');
        }

        $connection = false;

        if (! empty($config['url'])) {
            $connection = @pg_connect((string) $config['url']);
        }

        if ($connection === false) {
            $connectionString = sprintf(
                'host=%s port=%s dbname=%s user=%s password=%s sslmode=%s',
                $config['host'],
                $config['port'],
                $config['database'],
                $config['username'],
                $config['password'],
                $config['sslmode'] ?? 'prefer',
            );

            $connection = @pg_connect($connectionString);
        }

        if ($connection === false) {
            throw new \RuntimeException('No se pudo conectar a PostgreSQL para COPY: '.pg_last_error());
        }

        return $connection;
    }

    protected function createStagingTable(Connection $connection): void
    {
        $this->assertPgResult(pg_query($connection, $this->createStagingTableSql()), $connection);
    }

    protected function createStagingTableSql(): string
    {
        return <<<'SQL'
CREATE TEMP TABLE product_import_staging (
    sku varchar(60) NOT NULL,
    category_id bigint NOT NULL,
    name varchar(200) NOT NULL,
    slug varchar(200) NOT NULL,
    description text,
    price numeric(12, 2) NOT NULL,
    compare_at_price numeric(12, 2),
    stock integer NOT NULL,
    weight_kg numeric(8, 3),
    is_active boolean NOT NULL DEFAULT true,
    is_featured boolean NOT NULL DEFAULT false,
    familia varchar(120) NOT NULL,
    image_filename varchar(255)
) ON COMMIT DROP
SQL;
    }

    /**
     * @param  list<array<string, mixed>>  $staging
     */
    protected function copyIntoStaging(Connection $connection, array $staging): void
    {
        $rows = [];

        foreach ($staging as $payload) {
            $rows[] = [
                (string) $payload['sku'],
                (string) $payload['category_id'],
                (string) $payload['name'],
                (string) $payload['slug'],
                $this->formatCopyValue($payload['description'] ?? null),
                $this->formatCopyValue($payload['price']),
                $this->formatCopyValue($payload['compare_at_price'] ?? null),
                (string) $payload['stock'],
                $this->formatCopyValue($payload['weight_kg'] ?? null),
                $this->formatCopyBoolean($payload['is_active'] ?? true),
                $this->formatCopyBoolean($payload['is_featured'] ?? false),
                (string) $payload['familia'],
                $this->formatCopyValue($payload['image_filename'] ?? null),
            ];
        }

        if (! pg_copy_from($connection, 'product_import_staging', $rows)) {
            throw new \RuntimeException('COPY a staging falló: '.pg_last_error($connection));
        }
    }

    protected function runMergeStatements(Connection $connection, ?ProductImportProgress $progress = null): void
    {
        foreach ($this->mergeSqlStatements() as $label => $sql) {
            $progress?->phase($label);
            $this->assertPgResult(pg_query($connection, $sql), $connection);
        }
    }

    protected function runMergeStatementsViaLaravel(?ProductImportProgress $progress = null): void
    {
        foreach ($this->mergeSqlStatements() as $label => $sql) {
            $progress?->phase($label);
            DB::unprepared($sql);
        }
    }

    /**
     * @return array<string, string>
     */
    protected function mergeSqlStatements(): array
    {
        return [
            'Actualizando productos existentes...' => <<<'SQL'
UPDATE products AS p
SET
    category_id = s.category_id,
    name = s.name,
    slug = s.slug,
    description = s.description,
    price = s.price,
    compare_at_price = s.compare_at_price,
    stock = s.stock,
    weight_kg = s.weight_kg,
    is_active = s.is_active,
    is_featured = s.is_featured,
    familia = s.familia,
    image_filename = s.image_filename,
    updated_at = NOW()
FROM product_import_staging AS s
WHERE p.sku = s.sku
  AND p.deleted_at IS NULL
SQL,
            'Reactivando productos archivados...' => <<<'SQL'
UPDATE products AS p
SET
    deleted_at = NULL,
    category_id = s.category_id,
    name = s.name,
    slug = s.slug,
    description = s.description,
    price = s.price,
    compare_at_price = s.compare_at_price,
    stock = s.stock,
    weight_kg = s.weight_kg,
    is_active = s.is_active,
    is_featured = s.is_featured,
    familia = s.familia,
    image_filename = s.image_filename,
    updated_at = NOW()
FROM product_import_staging AS s
WHERE p.sku = s.sku
  AND p.deleted_at IS NOT NULL
SQL,
            'Insertando productos nuevos...' => <<<'SQL'
INSERT INTO products (
    category_id,
    sku,
    name,
    slug,
    description,
    price,
    compare_at_price,
    stock,
    weight_kg,
    is_active,
    is_featured,
    familia,
    image_filename,
    created_at,
    updated_at
)
SELECT
    s.category_id,
    s.sku,
    s.name,
    s.slug,
    s.description,
    s.price,
    s.compare_at_price,
    s.stock,
    s.weight_kg,
    s.is_active,
    s.is_featured,
    s.familia,
    s.image_filename,
    NOW(),
    NOW()
FROM product_import_staging AS s
WHERE NOT EXISTS (
    SELECT 1
    FROM products AS p
    WHERE p.sku = s.sku
)
SQL,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $staging
     * @return list<array<string, mixed>>
     */
    protected function formatStagingForDatabase(array $staging): array
    {
        return array_map(function (array $payload): array {
            return [
                'sku' => $payload['sku'],
                'category_id' => $payload['category_id'],
                'name' => $payload['name'],
                'slug' => $payload['slug'],
                'description' => $payload['description'] ?? null,
                'price' => $payload['price'],
                'compare_at_price' => $payload['compare_at_price'] ?? null,
                'stock' => $payload['stock'],
                'weight_kg' => $payload['weight_kg'] ?? null,
                'is_active' => (bool) ($payload['is_active'] ?? true),
                'is_featured' => (bool) ($payload['is_featured'] ?? false),
                'familia' => $payload['familia'],
                'image_filename' => $payload['image_filename'] ?? null,
            ];
        }, $staging);
    }

    protected function formatCopyValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '\\N';
        }

        return str_replace(["\t", "\r", "\n"], ' ', (string) $value);
    }

    protected function formatCopyBoolean(bool $value): string
    {
        return $value ? 't' : 'f';
    }

    protected function defaultDriver(): string
    {
        return (string) config('database.default');
    }

    /**
     * @param  \PgSql\Result|false  $result
     */
    protected function assertPgResult(mixed $result, Connection $connection): void
    {
        if ($result === false) {
            throw new \RuntimeException('Error PostgreSQL: '.pg_last_error($connection));
        }
    }
}

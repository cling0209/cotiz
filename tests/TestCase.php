<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $this->forceSqliteInMemoryEnvironment();

        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(
                'BLOQUEADO: los tests deben usar SQLite en memoria. '
                . "Conexión activa tras bootstrap: {$connection} / {$database}. "
                . 'Usa: docker compose run --rm test php artisan test'
            );
        }

        return $app;
    }

    protected function setUp(): void
    {
        $this->guardAgainstProductionDatabaseInProcess();

        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    /**
     * Evita migrate:fresh en Neon/Postgres cuando se usa docker compose exec app.
     */
    private function guardAgainstProductionDatabaseInProcess(): void
    {
        $host = (string) (getenv('DB_HOST') ?: $_ENV['DB_HOST'] ?? '');
        $connection = (string) (getenv('DB_CONNECTION') ?: $_ENV['DB_CONNECTION'] ?? '');
        $database = (string) (getenv('DB_DATABASE') ?: $_ENV['DB_DATABASE'] ?? '');

        if ($host !== '' && str_contains($host, 'neon.tech')) {
            throw new RuntimeException(
                'BLOQUEADO: DB_HOST apunta a Neon. Los tests ejecutan migrate:fresh y borran QA. '
                . 'Usa: docker compose run --rm test php artisan test'
            );
        }

        if ($connection === 'pgsql' && $database !== '' && $database !== ':memory:') {
            throw new RuntimeException(
                'BLOQUEADO: DB_CONNECTION=pgsql en el proceso del test (RefreshDatabase borra datos). '
                . "Base detectada: {$database}. Usa: docker compose run --rm test php artisan test"
            );
        }
    }

    private function forceSqliteInMemoryEnvironment(): void
    {
        $overrides = [
            'APP_ENV' => 'testing',
            'APP_URL' => 'http://localhost',
            'COTIZ_SISTEMA' => 'Cotiz',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_HOST' => '',
            'DB_PORT' => '',
            'DB_USERNAME' => '',
            'DB_PASSWORD' => '',
            'DB_URL' => '',
        ];

        foreach ($overrides as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

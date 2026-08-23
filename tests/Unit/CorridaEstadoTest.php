<?php

namespace Tests\Unit;

use App\Support\CorridaEstado;
use PHPUnit\Framework\TestCase;

class CorridaEstadoTest extends TestCase
{
    public function test_ultimo_error_normaliza_campo_error_a_mensaje(): void
    {
        $ultimo = CorridaEstado::ultimoError([
            ['codigo' => 'A-1', 'error' => 'primero'],
            ['codigo' => 'B-2', 'error' => 'ultimo fallo'],
        ]);

        $this->assertSame('ultimo fallo', $ultimo['mensaje'] ?? null);
        $this->assertSame('B-2', $ultimo['codigo'] ?? null);
    }

    public function test_ultimo_error_ignora_marca_encadenada_sin_mensaje(): void
    {
        $this->assertNull(CorridaEstado::ultimoError([
            ['encadenada' => true],
        ]));

        $ultimo = CorridaEstado::ultimoError([
            ['encadenada' => true],
            ['codigo' => 'X-1', 'error' => 'HTTP 504'],
        ]);

        $this->assertSame('HTTP 504', $ultimo['mensaje'] ?? null);
        $this->assertSame('X-1', $ultimo['codigo'] ?? null);
    }

    public function test_duracion_y_texto(): void
    {
        $this->assertSame(90, CorridaEstado::duracionSegundos('2026-08-22T12:00:00-04:00', '2026-08-22T12:01:30-04:00'));
        $this->assertSame('1m 30s', CorridaEstado::formatearSegundos(90));
        $this->assertNull(CorridaEstado::ultimoError([]));
        $this->assertNull(CorridaEstado::duracionSegundos('2026-08-22T12:00:00-04:00', null));
    }
}

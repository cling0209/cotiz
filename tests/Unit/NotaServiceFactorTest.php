<?php

namespace Tests\Unit;

use App\Services\NotaService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotaServiceFactorTest extends TestCase
{
    #[DataProvider('factorValidoProvider')]
    public function test_parse_factor_acepta_numero_y_coma(mixed $input, float $expected): void
    {
        $factor = app(NotaService::class)->parseFactorPrecioVenta($input);

        $this->assertNotNull($factor);
        $this->assertEqualsWithDelta($expected, $factor, 0.001);
    }

    #[DataProvider('factorInvalidoProvider')]
    public function test_parse_factor_rechaza_valores_invalidos(mixed $input): void
    {
        $this->assertNull(app(NotaService::class)->parseFactorPrecioVenta($input));
    }

    public static function factorValidoProvider(): array
    {
        return [
            'coma decimal' => ['1,30', 1.30],
            'coma un decimal' => ['1,5', 1.5],
            'punto decimal' => ['1.30', 1.30],
            'entero' => ['2', 2.0],
            'entero float' => [1.22, 1.22],
            'con espacios' => ['  1,22  ', 1.22],
            'coma final' => ['1,', 1.0],
            'punto final' => ['1.', 1.0],
        ];
    }

    public static function factorInvalidoProvider(): array
    {
        return [
            'vacio' => [''],
            'cero' => ['0'],
            'texto' => ['abc'],
            'mas de dos decimales' => ['1,234'],
        ];
    }
}

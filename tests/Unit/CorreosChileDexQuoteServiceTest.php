<?php

namespace Tests\Unit;

use App\Models\CorreosChileDexTarifa;
use App\Services\CorreosChileDexQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CorreosChileDexQuoteServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_tramo_elige_tope_que_cubre_el_peso(): void
    {
        $svc = new CorreosChileDexQuoteService;
        $tarifas = ['5.9' => 3830, '10' => 720, '20' => 530];

        [$key, $tarifa, $esPorKg] = $svc->resolverTramo($tarifas, 3.5);
        $this->assertSame('5.9', $key);
        $this->assertSame(3830, $tarifa);
        $this->assertFalse($esPorKg);

        [$key2, $tarifa2, $esPorKg2] = $svc->resolverTramo($tarifas, 8);
        $this->assertSame('10', $key2);
        $this->assertSame(720, $tarifa2);
        $this->assertTrue($esPorKg2);
    }

    public function test_cotizar_tramo_minimo_es_precio_fijo(): void
    {
        CorreosChileDexTarifa::query()->create([
            'origen' => 'SANTIAGO',
            'destino' => 'PUERTO MONTT',
            'destino_key' => CorreosChileDexTarifa::normalizeDestinoKey('PUERTO MONTT'),
            'recargo_pct' => null,
            'tarifas' => [
                '5.9' => 4680,
                '10' => 900,
                '20' => 730,
                '50' => 540,
                '100' => 490,
            ],
            'imported_at' => now(),
        ]);

        $resultado = app(CorreosChileDexQuoteService::class)->cotizar('Santiago', 'Puerto Montt', 2.3);

        $this->assertSame('5.9', $resultado['tramo_kg']);
        $this->assertFalse($resultado['es_por_kg']);
        $this->assertSame(4680, $resultado['tarifa_tramo']);
        $this->assertSame(4680, $resultado['precio_base']);
        $this->assertSame(4680, $resultado['precio']);
    }

    public function test_cotizar_tramo_superior_multiplica_peso_por_tarifa_kg(): void
    {
        CorreosChileDexTarifa::query()->create([
            'origen' => 'SANTIAGO',
            'destino' => 'PUERTO MONTT',
            'destino_key' => CorreosChileDexTarifa::normalizeDestinoKey('PUERTO MONTT'),
            'recargo_pct' => null,
            'tarifas' => [
                '5.9' => 4680,
                '10' => 900,
                '20' => 730,
                '50' => 540,
                '100' => 490,
            ],
            'imported_at' => now(),
        ]);

        // 54 kg → tramo 100 ($/kg 490) → 54 × 490 = 26.460
        $resultado = app(CorreosChileDexQuoteService::class)->cotizar('Santiago', 'Puerto Montt', 54);

        $this->assertSame('100', $resultado['tramo_kg']);
        $this->assertTrue($resultado['es_por_kg']);
        $this->assertSame(490, $resultado['tarifa_tramo']);
        $this->assertSame(26460, $resultado['precio_base']);
        $this->assertSame(26460, $resultado['precio']);
    }

    public function test_cotizar_aplica_recargo(): void
    {
        CorreosChileDexTarifa::query()->create([
            'origen' => 'SANTIAGO',
            'destino' => 'ALGARROBO',
            'destino_key' => CorreosChileDexTarifa::normalizeDestinoKey('ALGARROBO'),
            'recargo_pct' => 20,
            'tarifas' => ['5.9' => 1000, '10' => 500],
            'imported_at' => now(),
        ]);

        $resultado = app(CorreosChileDexQuoteService::class)->cotizar('Santiago', 'Algarrobo', 2);

        $this->assertSame('5.9', $resultado['tramo_kg']);
        $this->assertSame(1000, $resultado['precio_base']);
        $this->assertSame(20, $resultado['recargo_pct']);
        $this->assertSame(1200, $resultado['precio']);
    }

    public function test_cotizar_sin_tarifa_lanza(): void
    {
        $this->expectException(RuntimeException::class);

        app(CorreosChileDexQuoteService::class)->cotizar('Santiago', 'ComunaInexistenteXYZ', 1);
    }
}

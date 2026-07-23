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

    public function test_resolver_tramo_elige_mayor_tramo_que_no_supera_el_peso(): void
    {
        $svc = new CorreosChileDexQuoteService;
        $tarifas = ['5.9' => 3830, '10' => 720, '20' => 530, '50' => 540, '100' => 490];

        [$key, $precio] = $svc->resolverTramo($tarifas, 3.5);
        $this->assertSame('5.9', $key);
        $this->assertSame(3830, $precio);

        [$key2, $precio2] = $svc->resolverTramo($tarifas, 8);
        $this->assertSame('5.9', $key2);
        $this->assertSame(3830, $precio2);

        [$key3, $precio3] = $svc->resolverTramo($tarifas, 54);
        $this->assertSame('50', $key3);
        $this->assertSame(540, $precio3);
    }

    public function test_cotizar_usa_valor_fijo_de_la_celda(): void
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

        $bajo = app(CorreosChileDexQuoteService::class)->cotizar('Santiago', 'Puerto Montt', 2.3);
        $this->assertSame('5.9', $bajo['tramo_kg']);
        $this->assertSame(4680, $bajo['precio_base']);
        $this->assertSame(4680, $bajo['precio']);

        // > 50 kg → columna 50 = $540 (se suma al unitario)
        $alto = app(CorreosChileDexQuoteService::class)->cotizar('Santiago', 'Puerto Montt', 54);
        $this->assertSame('50', $alto['tramo_kg']);
        $this->assertSame(540, $alto['precio_base']);
        $this->assertSame(540, $alto['precio']);
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

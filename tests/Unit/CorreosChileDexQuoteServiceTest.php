<?php

namespace Tests\Unit;

use App\Models\CorreosChileDexTarifa;
use App\Services\CorreosChileDexQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CorreosChileDexQuoteServiceTest extends TestCase
{
    public function test_resolver_tramo_elige_tope_que_cubre_el_peso(): void
    {
        $svc = new CorreosChileDexQuoteService;
        $tarifas = ['5.9' => 3830, '10' => 720, '20' => 530];

        [$key, $precio] = $svc->resolverTramo($tarifas, 3.5);
        $this->assertSame('5.9', $key);
        $this->assertSame(3830, $precio);

        [$key2, $precio2] = $svc->resolverTramo($tarifas, 8);
        $this->assertSame('10', $key2);
        $this->assertSame(720, $precio2);
    }
}

class CorreosChileDexQuoteServiceDbTest extends TestCase
{
    use RefreshDatabase;

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

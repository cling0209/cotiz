<?php

namespace Tests\Feature;

use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Models\NotaMpOferta;
use App\Models\NotaMpOfertaLinea;
use App\Models\NotaMpSeguimiento;
use App\Models\User;
use App\Services\CompraAgilCompetenciaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompraAgilCompetenciaTest extends TestCase
{
    use RefreshDatabase;

    public function test_cruza_por_orden_y_separa_cantidad_propia_de_otros(): void
    {
        config([
            'cotiz.reicol_rut' => '76.356.855-5',
            'cotiz.romulo_rut' => '76.185.139-K',
            'cotiz.empresa_rut' => '76.185.139-K',
        ]);

        $this->nota(14865, '2026-09-04 12:00:00');
        Maeprod::query()->create([
            'prod_item' => '12345',
            'prod_nombre' => 'Huellero individual',
            'prod_valor' => 800,
        ]);
        NotaDetalle::query()->create([
            'nronota' => 14865,
            'prod_item' => '12345',
            'prod_valor' => 800,
            'cantidad' => 100,
            'fechahora' => now(),
            'orden' => 1,
            'prod_descripcion_maestro' => 'Huellero individual',
        ]);
        NotaDetalle::query()->create([
            'nronota' => 14865,
            'prod_item' => '99999',
            'prod_valor' => 500,
            'cantidad' => 10,
            'fechahora' => now(),
            'orden' => 2,
            'prod_descripcion_maestro' => 'Otro producto',
        ]);

        $glt = $this->oferta(14865, '77738709-K', 'COMERCIALIZADORA GLT SPA', false, false);
        $this->linea($glt, '44121622', 100, 756);
        $this->linea($glt, '999', 10, 100);

        $propio = $this->oferta(14865, '76185139-K', 'ROMULO', false, true);
        $this->linea($propio, '44121622', 100, 800);
        $this->linea($propio, '999', 10, 500);

        $vergio = $this->oferta(14865, '96972190-2', 'DISTRIBUIDORA VERGIO SPA', true, false);
        $this->linea($vergio, '44121622', 100, 950);
        $this->linea($vergio, '999', 10, 400);

        $servicio = app(CompraAgilCompetenciaService::class);
        $filas = $servicio->listado([])->items();

        $huellero = collect($filas)->firstWhere('prod_item', '12345');
        $this->assertNotNull($huellero);
        $this->assertSame('Huellero individual', $huellero['prod_nombre']);
        $this->assertSame(0.0, $huellero['cant_propia']);
        $this->assertSame(100.0, $huellero['cant_otros']);
        $this->assertSame(800, $huellero['tu_precio']);
        $this->assertSame(756, $huellero['precio_min']);
        $this->assertSame(950, $huellero['precio_max']);

        $detalle = $servicio->detalle('12345', []);
        $this->assertNotNull($detalle);
        $proveedores = collect($detalle['lineas'])->pluck('proveedor')->all();
        $this->assertContains('Tú', $proveedores);
        $this->assertContains('COMERCIALIZADORA GLT SPA', $proveedores);
        $this->assertNotContains('999', collect($detalle['lineas'])->pluck('precio_unitario')->all());
        $adjudicada = collect($detalle['lineas'])->firstWhere('proveedor', 'DISTRIBUIDORA VERGIO SPA');
        $this->assertSame(100.0, $adjudicada['cantidad_adjudicada']);
        $tu = collect($detalle['lineas'])->firstWhere('proveedor', 'Tú');
        $this->assertSame(0.0, $tu['cantidad_adjudicada']);
    }

    public function test_sin_fechas_trae_todo_y_el_rango_recorta(): void
    {
        $this->nota(1, '2026-01-10 10:00:00');
        $this->nota(2, '2026-06-10 10:00:00');
        foreach ([1, 2] as $nro) {
            NotaDetalle::query()->create([
                'nronota' => $nro,
                'prod_item' => 'P1',
                'prod_valor' => 1000,
                'cantidad' => 5,
                'fechahora' => now(),
                'orden' => 1,
                'prod_descripcion_maestro' => 'Producto',
            ]);
            $oferta = $this->oferta($nro, '11111111-1', 'OTRO', true, false);
            $this->linea($oferta, 'X', 5, 900);
        }

        $servicio = app(CompraAgilCompetenciaService::class);
        $todo = $servicio->listado([])->items();
        $this->assertSame(10.0, $todo[0]['cant_otros']);

        $recorte = $servicio->listado([
            'fecha_desde' => '2026-06-01',
            'fecha_hasta' => '2026-06-30',
        ])->items();
        $this->assertCount(1, $recorte);
        $this->assertSame(5.0, $recorte[0]['cant_otros']);
    }

    public function test_pantalla_no_muestra_codigo_mp(): void
    {
        $admin = User::factory()->create(['perfil' => User::PERFIL_SUPERADMIN]);

        $this->withoutMiddleware()
            ->actingAs($admin)
            ->get(route('admin.compra-agil.analisis.index'))
            ->assertOk()
            ->assertSee('Cód. propio')
            ->assertSee('Cant. propia')
            ->assertDontSee('Cód. MP');
    }

    private function nota(int $nronota, string $cierre): void
    {
        Nota::query()->create([
            'nronota' => $nronota,
            'descripcion' => 'Nota '.$nronota,
            'fecha' => '2026-09-01',
            'usuario' => 'admin',
            'empresa' => 'Cliente',
            'encargado' => $nronota.'-1-COT26',
            'nota_softland' => 10000 + $nronota,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1,
        ]);
        NotaMpSeguimiento::query()->create([
            'nronota' => $nronota,
            'codigo_proceso' => $nronota.'-1-COT26',
            'fecha_cierre' => $cierre,
            'resultado_propio' => 'cerrada',
            'finalizado' => true,
        ]);
    }

    private function oferta(int $nronota, string $rut, string $razon, bool $seleccionado, bool $propio): NotaMpOferta
    {
        return NotaMpOferta::query()->create([
            'nronota' => $nronota,
            'rut_proveedor' => $rut,
            'razon_social' => $razon,
            'proveedor_seleccionado' => $seleccionado,
            'es_propio' => $propio,
            'inadmisible' => false,
            'monto_total' => 1,
        ]);
    }

    private function linea(NotaMpOferta $oferta, string $codigo, float $cantidad, int $precio): void
    {
        NotaMpOfertaLinea::query()->create([
            'oferta_id' => $oferta->id,
            'codigo_producto' => $codigo,
            'descripcion' => 'Linea '.$codigo,
            'cantidad' => $cantidad,
            'precio_unitario' => $precio,
            'monto_total' => (int) round($cantidad * $precio),
        ]);
    }
}

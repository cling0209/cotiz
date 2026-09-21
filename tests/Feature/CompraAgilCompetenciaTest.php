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
        $this->linea($propio, '44121622', 100, 26);
        $this->linea($propio, '999', 10, 500);

        $vergio = $this->oferta(14865, '96972190-2', 'DISTRIBUIDORA VERGIO SPA', true, false);
        $this->linea($vergio, '44121622', 100, 950);
        $this->linea($vergio, '999', 10, 400);

        $servicio = app(CompraAgilCompetenciaService::class);
        $filas = $servicio->listado([])->items();

        $huellero = collect($filas)->firstWhere('prod_item', '12345');
        $this->assertNotNull($huellero);
        $this->assertSame('Huellero individual', $huellero['prod_nombre']);
        $this->assertSame(100.0, $huellero['cant_propia']);
        $this->assertSame(200.0, $huellero['cant_otros']);
        $this->assertSame(300.0, $huellero['cant_total']);
        $this->assertSame(0.0, $huellero['adjudicada_propia']);
        $this->assertSame(100.0, $huellero['adjudicada_otros']);
        $this->assertSame(200.0, $huellero['nadie_gano']);
        $this->assertSame(
            $huellero['cant_total'],
            $huellero['adjudicada_propia'] + $huellero['adjudicada_otros'] + $huellero['nadie_gano'],
        );
        $this->assertSame(26, $huellero['tu_precio']);
        $this->assertSame(756, $huellero['precio_min']);
        $this->assertSame(950, $huellero['precio_max']);
        $this->assertSame(14865, $huellero['nronota_cerrada']);

        $detalle = $servicio->detalle('12345', []);
        $this->assertNotNull($detalle);
        $proveedores = collect($detalle['lineas'])->pluck('proveedor')->all();
        $this->assertSame(['DISTRIBUIDORA VERGIO SPA'], $proveedores);
        $this->assertSame(100.0, $detalle['lineas'][0]['cantidad_adjudicada']);
        $this->assertArrayNotHasKey('precio_unitario', $detalle['lineas'][0]);

        $precios = $servicio->detallePrecios('12345', []);
        $this->assertSame(14865, $precios['nronota']);
        $this->assertSame(800, $precios['precio_catalogo']);
        $porPrecio = collect($precios['lineas'])->keyBy('proveedor');
        $this->assertSame(26, $porPrecio['Tú']['precio_unitario']);
        $this->assertTrue($porPrecio['Tú']['es_propio']);
        $this->assertSame(756, $porPrecio['COMERCIALIZADORA GLT SPA']['precio_unitario']);
        $this->assertSame(950, $porPrecio['DISTRIBUIDORA VERGIO SPA']['precio_unitario']);
        $this->assertArrayNotHasKey('ROMULO', $porPrecio->all());

        $this->assertSame(14865, $servicio->nronotaUltimaCerrada('12345', []));
        $this->assertSame([1], $servicio->ordenesProductoEnNota('12345', 14865));
        $this->assertSame([2], $servicio->ordenesProductoEnNota('99999', 14865));
        $lineasFiltradas = $servicio->filtrarLineasPorOrdenes(
            collect([
                (object) ['codigo_producto' => '44121622'],
                (object) ['codigo_producto' => '999'],
            ]),
            [1],
        );
        $this->assertCount(1, $lineasFiltradas);
        $this->assertSame('44121622', $lineasFiltradas[0]->codigo_producto);
    }

    public function test_min_max_salen_de_la_ultima_cotizacion(): void
    {
        $this->nota(1, '2026-01-10 10:00:00');
        $this->nota(2, '2026-06-10 10:00:00');
        foreach ([1 => 100, 2 => 900] as $nro => $precioPropio) {
            NotaDetalle::query()->create([
                'nronota' => $nro,
                'prod_item' => 'P1',
                'prod_valor' => $precioPropio,
                'cantidad' => 5,
                'fechahora' => now(),
                'orden' => 1,
                'prod_descripcion_maestro' => 'Producto',
            ]);
        }
        $vieja = $this->oferta(1, '11111111-1', 'VIEJO', true, false);
        $this->linea($vieja, 'X', 5, 50);
        $nueva = $this->oferta(2, '22222222-2', 'NUEVO', true, false);
        $this->linea($nueva, 'X', 8, 1200);
        $propia = $this->oferta(2, '76185139-K', 'ROMULO', false, true);
        $this->linea($propia, 'X', 5, 850);

        $fila = app(CompraAgilCompetenciaService::class)->listado([])->items()[0];
        $this->assertSame(850, $fila['tu_precio']);
        $this->assertSame(1200, $fila['precio_min']);
        $this->assertSame(1200, $fila['precio_max']);

        $this->assertSame(0.0, $fila['adjudicada_propia']);
        $this->assertSame(13.0, $fila['adjudicada_otros']);

        $detalle = app(CompraAgilCompetenciaService::class)->detalle('P1', []);
        $porEmpresa = collect($detalle['lineas'])->keyBy('proveedor');
        $this->assertSame(5.0, $porEmpresa['VIEJO']['cantidad_adjudicada']);
        $this->assertSame(8.0, $porEmpresa['NUEVO']['cantidad_adjudicada']);
        $this->assertSame(1200, $detalle['precio_min']);
        $this->assertSame(1200, $detalle['precio_max']);
    }

    public function test_min_max_retrocede_si_la_ultima_nota_no_tiene_otra_empresa(): void
    {
        $this->nota(1, '2026-01-10 10:00:00');
        $this->nota(2, '2026-06-10 10:00:00');
        foreach ([1 => 100, 2 => 900] as $nro => $precioPropio) {
            NotaDetalle::query()->create([
                'nronota' => $nro,
                'prod_item' => 'P1',
                'prod_valor' => $precioPropio,
                'cantidad' => 5,
                'fechahora' => now(),
                'orden' => 1,
                'prod_descripcion_maestro' => 'Producto',
            ]);
        }
        $vieja = $this->oferta(1, '11111111-1', 'OTRA', false, false);
        $this->linea($vieja, 'X', 4, 40);
        $otra = $this->oferta(1, '22222222-2', 'OTRA MAS', false, false);
        $this->linea($otra, 'X', 4, 80);
        $propiaVieja = $this->oferta(1, '76185139-K', 'ROMULO', false, true);
        $this->linea($propiaVieja, 'X', 5, 30);

        $fila = app(CompraAgilCompetenciaService::class)->listado([])->items()[0];
        $this->assertSame(30, $fila['tu_precio']);
        $this->assertSame(40, $fila['precio_min']);
        $this->assertSame(80, $fila['precio_max']);

        $precios = app(CompraAgilCompetenciaService::class)->detallePrecios('P1', []);
        $this->assertSame(1, $precios['nronota']);
        $this->assertSame(100, $precios['precio_catalogo']);
        $porPrecio = collect($precios['lineas'])->keyBy('proveedor');
        $this->assertSame(30, $porPrecio['Tú']['precio_unitario']);
        $this->assertSame(40, $porPrecio['OTRA']['precio_unitario']);
        $this->assertSame(80, $porPrecio['OTRA MAS']['precio_unitario']);
        $this->assertArrayNotHasKey('ROMULO', $porPrecio->all());
    }

    public function test_tu_precio_ignora_la_nota_si_no_cotizo_otra_empresa(): void
    {
        $this->nota(1, '2026-01-10 10:00:00');
        $this->nota(2, '2026-06-10 10:00:00');
        $this->nota(3, '2026-09-04 10:00:00');
        foreach ([1 => 80, 2 => 80, 3 => 80] as $nro => $precioCatalogo) {
            NotaDetalle::query()->create([
                'nronota' => $nro,
                'prod_item' => 'REYSOL17',
                'prod_valor' => $precioCatalogo,
                'cantidad' => 5,
                'fechahora' => now(),
                'orden' => 1,
                'prod_descripcion_maestro' => 'Kit',
            ]);
        }
        $compartida = $this->oferta(1, '76185139-K', 'ROMULO', false, true);
        $this->linea($compartida, 'X', 5, 90);
        $otra = $this->oferta(1, '11111111-1', 'OTRA', false, false);
        $this->linea($otra, 'X', 5, 100);
        $soloOtros = $this->oferta(2, '22222222-2', 'SOLO OTROS', false, false);
        $this->linea($soloOtros, 'X', 5, 194);
        $soloPropia = $this->oferta(3, '76185139-K', 'ROMULO', false, true);
        $this->linea($soloPropia, 'X', 5, 859850);

        $fila = app(CompraAgilCompetenciaService::class)->listado([])->items()[0];
        $this->assertSame(90, $fila['tu_precio']);
        $this->assertSame(100, $fila['precio_min']);
        $this->assertSame(100, $fila['precio_max']);
        $this->assertSame(1, $fila['nronota_mercado']);
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
        $this->assertSame(20.0, $todo[0]['cant_total']);
        $this->assertSame(0.0, $todo[0]['adjudicada_propia']);
        $this->assertSame(10.0, $todo[0]['adjudicada_otros']);

        $recorte = $servicio->listado([
            'fecha_desde' => '2026-06-01',
            'fecha_hasta' => '2026-06-30',
        ])->items();
        $this->assertCount(1, $recorte);
        $this->assertSame(10.0, $recorte[0]['cant_total']);
        $this->assertSame(5.0, $recorte[0]['adjudicada_otros']);
    }

    public function test_pantalla_no_muestra_codigo_mp(): void
    {
        $admin = User::factory()->create(['perfil' => User::PERFIL_SUPERADMIN]);

        $this->withoutMiddleware()
            ->actingAs($admin)
            ->get(route('admin.compra-agil.analisis.index'))
            ->assertOk()
            ->assertSee('Cód. propio')
            ->assertSee('Cant. total')
            ->assertSee('Nadie se ganó')
            ->assertSee('Adjudicadas otros')
            ->assertSee('Excel')
            ->assertSee('Total proveedores')
            ->assertSee('Ver nota')
            ->assertDontSee('Tu precio')
            ->assertDontSee('Más barato')
            ->assertDontSee('Más caro')
            ->assertDontSee('Cód. MP');
    }

    public function test_excel_exporta_todas_las_filas_del_filtro(): void
    {
        $this->nota(1, '2026-06-10 10:00:00');
        foreach (['P1' => 1, 'P2' => 2] as $item => $orden) {
            NotaDetalle::query()->create([
                'nronota' => 1,
                'prod_item' => $item,
                'prod_valor' => 500,
                'cantidad' => 4,
                'fechahora' => now(),
                'orden' => $orden,
                'prod_descripcion_maestro' => 'Producto '.$item,
            ]);
        }
        $oferta = $this->oferta(1, '11111111-1', 'OTRO', true, false);
        $this->linea($oferta, 'A', 3, 100);
        $this->linea($oferta, 'B', 7, 200);

        $admin = User::factory()->create(['perfil' => User::PERFIL_SUPERADMIN]);
        $response = $this->withoutMiddleware()
            ->actingAs($admin)
            ->get(route('admin.compra-agil.analisis.excel', ['por_pagina' => 1]));

        $response->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'comp').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet();
        @unlink($tmp);

        $this->assertSame('Cód. propio', $sheet->getCell('A1')->getValue());
        $this->assertSame('Nadie se ganó', $sheet->getCell('F1')->getValue());
        $this->assertNull($sheet->getCell('G1')->getValue());
        $this->assertSame('Cant. total', $sheet->getCell('C1')->getValue());
        $this->assertSame('Adjudicada propio', $sheet->getCell('D1')->getValue());
        $codigos = [$sheet->getCell('A2')->getValue(), $sheet->getCell('A3')->getValue()];
        sort($codigos);
        $this->assertSame(['P1', 'P2'], $codigos);
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

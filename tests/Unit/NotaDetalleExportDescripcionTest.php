<?php

namespace Tests\Unit;

use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Models\User;
use App\Services\NotaDetalleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotaDetalleExportDescripcionTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_usa_descripcion_maestro_editada_sobre_nombre_catalogo(): void
    {
        User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);

        Maeprod::query()->create([
            'prod_item' => 'ASEO001',
            'prod_nombre' => 'LIMPIADOR DE PISOS CON AROMAS 5 LTS',
            'prod_valor' => 4500,
            'prod_valor_costo' => 3200,
        ]);

        $nota = Nota::query()->create([
            'nronota' => 910001,
            'descripcion' => 'Export descripcion editada',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => '',
            'encargado' => 'TEST-EXPORT-910001',
            'nota_softland' => 91000100,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        NotaDetalle::query()->create([
            'nronota' => $nota->nronota,
            'prod_item' => 'ASEO001',
            'prod_valor' => 4500,
            'cantidad' => 1,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 3200,
            'prod_descripcion_maestro' => 'DESCRIPCION EDITADA EN LA COTIZACION',
        ]);

        $fila = app(NotaDetalleService::class)->lineasDeNota($nota)->first();

        $this->assertSame('DESCRIPCION EDITADA EN LA COTIZACION', $fila['prod_nombre']);
    }

    public function test_export_usa_nombre_catalogo_si_no_hay_descripcion_maestro(): void
    {
        User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);

        Maeprod::query()->create([
            'prod_item' => 'CARPUSI013',
            'prod_nombre' => 'CARPETA VINIL JM OFICIO AZUL',
            'prod_valor' => 350,
            'prod_valor_costo' => 350,
        ]);

        $nota = Nota::query()->create([
            'nronota' => 910002,
            'descripcion' => 'Export sin descripcion maestro',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => '',
            'encargado' => 'TEST-EXPORT-910002',
            'nota_softland' => 91000200,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        NotaDetalle::query()->create([
            'nronota' => $nota->nronota,
            'prod_item' => 'CARPUSI013',
            'prod_valor' => 350,
            'cantidad' => 2,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 350,
        ]);

        $fila = app(NotaDetalleService::class)->lineasDeNota($nota)->first();

        $this->assertSame('CARPETA VINIL JM OFICIO AZUL', $fila['prod_nombre']);
    }
}

<?php

namespace Tests\Unit;

use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\User;
use App\Services\NotaDetalleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotaDetalleAgregarLineaDescripcionTest extends TestCase
{
    use RefreshDatabase;

    public function test_agregar_linea_vinculada_usa_nombre_maestro_no_agile(): void
    {
        User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);

        Maeprod::query()->create([
            'prod_item' => 'RESMA03',
            'prod_nombre' => 'RESMA OFICIO BLANCA MAESTRO',
            'prod_valor' => 2500,
            'prod_valor_costo' => 1800,
            'prod_familia' => 'PAPEL',
        ]);

        $nota = Nota::query()->create([
            'nronota' => 900001,
            'descripcion' => 'Test desc maestro',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => '',
            'encargado' => 'TEST-DESC-900001',
            'nota_softland' => 90000100,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        $linea = app(NotaDetalleService::class)->agregarLinea(
            $nota,
            'RESMA03',
            1,
            2500,
            1800,
            'admin',
            '14111507',
            'resma tamaño oficio, hoja blanca para imprimir',
        );

        $this->assertSame(
            'resma tamaño oficio, hoja blanca para imprimir',
            $linea->prod_descripcion_agile,
        );
        $this->assertSame(
            'RESMA OFICIO BLANCA MAESTRO',
            $linea->prod_descripcion_maestro,
        );
    }

    public function test_agregar_linea_pendiente_copia_agile_en_maestro(): void
    {
        User::factory()->create(['username' => 'admin', 'perfil' => User::PERFIL_SUPERADMIN]);

        $nota = Nota::query()->create([
            'nronota' => 900002,
            'descripcion' => 'Test pendiente',
            'fecha' => now()->toDateString(),
            'usuario' => 'admin',
            'empresa' => '',
            'encargado' => 'TEST-DESC-900002',
            'nota_softland' => 90000200,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);

        $linea = app(NotaDetalleService::class)->agregarLineaAgilePendiente(
            $nota,
            '14111531',
            'cuaderno universitario matemáticas, 100 hojas con espiral',
            2,
        );

        $this->assertSame(
            'cuaderno universitario matemáticas, 100 hojas con espiral',
            $linea->prod_descripcion_agile,
        );
        $this->assertSame(
            'cuaderno universitario matemáticas, 100 hojas con espiral',
            $linea->prod_descripcion_maestro,
        );
    }
}

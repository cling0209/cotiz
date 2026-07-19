<?php

namespace Tests\Unit;

use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\OportunidadEncontrada;
use App\Models\User;
use App\Services\CompraAgilImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompraAgilImportGeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_importar_desde_datos_aplica_geo_factor_y_dias_rm(): void
    {
        config([
            'cotiz.factor_precio_venta_rm' => 1.22,
            'cotiz.factor_precio_venta_otras' => 1.30,
            'cotiz.diashabiles_rm' => 5,
            'cotiz.diashabiles_otras' => 10,
        ]);

        $user = User::factory()->create(['username' => 'ejecgeo', 'perfil' => User::PERFIL_EJECUTIVO]);
        Maeprod::query()->create([
            'prod_item' => 'GEO001',
            'prod_nombre' => 'Producto geo',
            'prod_valor' => 1220,
            'prod_valor_costo' => 1000,
            'prod_familia' => 'PAPEL',
        ]);

        $nota = Nota::query()->create([
            'nronota' => 90001,
            'descripcion' => 'Borrador',
            'fecha' => now()->toDateString(),
            'usuario' => $user->username,
            'empresa' => '',
            'encargado' => '',
            'diashabiles' => 2,
            'factor_precio_venta' => 1.22,
            'sistema' => 'Cotiz',
            'enviadoapi' => 0,
        ]);

        $datos = [
            'cabecera' => [
                'codigo_cotizacion' => '9999-1-COT26',
                'empresa' => 'Municipalidad Test',
                'rutempresa' => '69010100-0',
                'nombre' => 'Compra de prueba',
                'region' => 13,
                'nombre_region' => 'Metropolitana',
                'comuna' => 'Las Condes',
                'direccion_entrega' => 'Av. Apoquindo 3000',
            ],
            'lineas' => [
                [
                    'id_agile' => 'AG1',
                    'descripcion' => 'Producto geo',
                    'cantidad' => 2,
                    'categoria' => 'Producto geo',
                    'estado' => 'vinculado',
                    'es_sugerencia' => false,
                    'producto' => [
                        'prod_item' => 'GEO001',
                        'prod_nombre' => 'Producto geo',
                        'prod_valor' => 1220,
                        'prod_valor_costo' => 1000,
                    ],
                ],
            ],
        ];

        app(CompraAgilImportService::class)->aplicarDesdeDatos($nota, $datos, $user->username);

        $nota->refresh();
        $this->assertSame(13, (int) $nota->region);
        $this->assertSame('Metropolitana', $nota->nombre_region);
        $this->assertSame('Las Condes', $nota->comuna);
        $this->assertSame('Av. Apoquindo 3000', $nota->direccion_entrega);
        $this->assertSame(5, (int) $nota->diashabiles);
        $this->assertEqualsWithDelta(1.22, (float) $nota->factor_precio_venta, 0.001);
    }

    public function test_importar_otra_region_usa_factor_130_y_10_dias(): void
    {
        config([
            'cotiz.factor_precio_venta_rm' => 1.22,
            'cotiz.factor_precio_venta_otras' => 1.30,
            'cotiz.diashabiles_rm' => 5,
            'cotiz.diashabiles_otras' => 10,
        ]);

        $user = User::factory()->create(['username' => 'ejecgeo2', 'perfil' => User::PERFIL_EJECUTIVO]);
        $nota = Nota::query()->create([
            'nronota' => 90002,
            'descripcion' => 'Borrador',
            'fecha' => now()->toDateString(),
            'usuario' => $user->username,
            'empresa' => '',
            'encargado' => '',
            'diashabiles' => 2,
            'factor_precio_venta' => 1.22,
            'sistema' => 'Cotiz',
            'enviadoapi' => 0,
        ]);

        $datos = [
            'cabecera' => [
                'codigo_cotizacion' => '8888-1-COT26',
                'empresa' => 'Hospital Sur',
                'rutempresa' => '61000000-0',
                'nombre' => 'Compra regiones',
                'region' => 8,
                'nombre_region' => 'Biobío',
                'comuna' => 'Concepción',
                'direccion_entrega' => 'Calle Uno 10',
            ],
            'lineas' => [],
        ];

        // Sin líneas: aplicarConPreview lanza si no hay productos y cabecera vacía de código... 
        // Con código y sin líneas en desde=0 hasta=0: total=0, agregadas=0, lanza "No se detectaron productos".
        // Usamos enriquecer + modificar vía preview con una línea pendiente.
        $datos['lineas'][] = [
            'id_agile' => 'X1',
            'descripcion' => 'Sin match xyz',
            'cantidad' => 1,
            'categoria' => 'Sin match',
        ];

        app(CompraAgilImportService::class)->aplicarDesdeDatos($nota, $datos, $user->username);

        $nota->refresh();
        $this->assertSame(8, (int) $nota->region);
        $this->assertSame(10, (int) $nota->diashabiles);
        $this->assertEqualsWithDelta(1.30, (float) $nota->factor_precio_venta, 0.001);
        $this->assertSame('Concepción', $nota->comuna);
    }

    public function test_enriquecer_desde_oportunidad_completa_direccion(): void
    {
        OportunidadEncontrada::query()->create([
            'codigo' => '7777-1-COT26',
            'nombre' => 'Opp',
            'region' => 5,
            'nombre_region' => 'Valparaíso',
            'comuna' => 'Viña del Mar',
            'direccion' => 'Calle Marina 50',
            'fecha_busqueda' => now()->toDateString(),
            'indice_region_config' => 0,
        ]);

        $datos = [
            'cabecera' => [
                'codigo_cotizacion' => '7777-1-COT26',
                'empresa' => 'Muni',
                'rutempresa' => '1-9',
                'nombre' => 'Test',
                'region' => null,
                'nombre_region' => '',
                'comuna' => '',
                'direccion_entrega' => '',
            ],
            'lineas' => [],
        ];

        $out = app(CompraAgilImportService::class)->enriquecerCabeceraDesdeOportunidad($datos);

        $this->assertSame(5, $out['cabecera']['region']);
        $this->assertSame('Valparaíso', $out['cabecera']['nombre_region']);
        $this->assertSame('Viña del Mar', $out['cabecera']['comuna']);
        $this->assertSame('Calle Marina 50', $out['cabecera']['direccion_entrega']);
    }
}

<?php

namespace Tests\Unit;

use App\Models\AgileMaeprod;
use App\Models\Maeprod;
use App\Services\AgileVinculoAprendizajeService;
use App\Services\CompraAgilPayloadMapper;
use App\Services\CompraAgilTextoParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgileVinculoAprendizajeServiceTest extends TestCase
{
    use RefreshDatabase;

    private AgileVinculoAprendizajeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AgileVinculoAprendizajeService::class);

        Maeprod::query()->create([
            'prod_item' => 'PAPEL001',
            'prod_nombre' => 'GREDAS ESCOLARES 1 KG',
            'prod_valor' => 1200,
            'prod_valor_costo' => 900,
            'prod_familia' => 'PAPEL',
        ]);

        Maeprod::query()->create([
            'prod_item' => 'ARTE001',
            'prod_nombre' => 'TEMPERAS 12 COLORES',
            'prod_valor' => 3500,
            'prod_valor_costo' => 2800,
            'prod_familia' => 'ARTE',
        ]);
    }

    public function test_vincula_por_descripcion_aprendida_exacta(): void
    {
        $desc = 'GREDAS ESCOLARES DE 1 KILO';
        $this->service->guardarAprendizaje($desc, 'PAPEL001', '14111509');

        $resultado = $this->service->resolverParaImportacion($desc);

        $this->assertSame('vinculado', $resultado['estado']);
        $this->assertFalse($resultado['es_sugerencia']);
        $this->assertSame('PAPEL001', $resultado['producto']['prod_item']);
        $this->assertSame('aprendido_exacto', $resultado['origen']);
    }

    public function test_mismo_codigo_mp_distintas_descripciones_no_colisionan(): void
    {
        $this->service->guardarAprendizaje('GREDAS ESCOLARES DE 1 KILO', 'PAPEL001', '14111509');
        $this->service->guardarAprendizaje('TEMPERAS DE 12 COLORES', 'ARTE001', '14111509');

        $gredas = $this->service->resolverParaImportacion('GREDAS ESCOLARES DE 1 KILO');
        $temperas = $this->service->resolverParaImportacion('TEMPERAS DE 12 COLORES');

        $this->assertSame('PAPEL001', $gredas['producto']['prod_item']);
        $this->assertSame('ARTE001', $temperas['producto']['prod_item']);
    }

    public function test_sin_aprendizaje_propone_maeprod_por_similitud(): void
    {
        $resultado = $this->service->resolverParaImportacion('GREDAS ESCOLARES DE 1 KILO');

        $this->assertSame('pendiente', $resultado['estado']);
        $this->assertTrue($resultado['es_sugerencia']);
        $this->assertSame('PAPEL001', $resultado['producto']['prod_item']);
        $this->assertSame('maeprod_similitud', $resultado['origen']);
    }

    public function test_mapper_mantiene_codigo_mp_como_referencia(): void
    {
        $mapper = new CompraAgilPayloadMapper(new CompraAgilTextoParserService);
        $datos = $mapper->fromDetalle([
            'codigo' => '1161-172-COT26',
            'productos_solicitados' => [
                [
                    'codigo_producto' => '14111509',
                    'nombre' => 'Artículos de papelería',
                    'descripcion' => 'GREDAS ESCOLARES DE 1 KILO',
                    'cantidad' => 15,
                ],
                [
                    'codigo_producto' => '14111509',
                    'nombre' => 'Artículos de papelería',
                    'descripcion' => 'TEMPERAS DE 12 COLORES',
                    'cantidad' => 5,
                ],
            ],
        ]);

        $this->assertCount(2, $datos['lineas']);
        $this->assertSame('14111509', $datos['lineas'][0]['id_agile']);
        $this->assertSame('14111509', $datos['lineas'][1]['id_agile']);
        $this->assertNotSame($datos['lineas'][0]['descripcion'], $datos['lineas'][1]['descripcion']);
    }

    public function test_guardar_aprendizaje_actualiza_vinculo_existente(): void
    {
        $desc = 'GREDAS ESCOLARES DE 1 KILO';
        $this->service->guardarAprendizaje($desc, 'PAPEL001', '14111509');
        $this->service->guardarAprendizaje($desc, 'ARTE001', '14111509');

        $hash = $this->service->hashDescripcion($desc);
        $row = AgileMaeprod::query()->where('descripcion_norm_hash', $hash)->first();

        $this->assertNotNull($row);
        $this->assertSame('ARTE001', $row->prod_item);
    }
}

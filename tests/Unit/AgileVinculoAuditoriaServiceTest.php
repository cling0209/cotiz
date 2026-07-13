<?php

namespace Tests\Unit;

use App\Models\AgileMaeprod;
use App\Models\Maeprod;
use App\Services\AgileVinculoAuditoriaService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class AgileVinculoAuditoriaServiceTest extends TestCase
{
    private AgileVinculoAuditoriaService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AgileVinculoAuditoriaService::class);
    }

    public function test_marca_mala_carton_vinculado_a_tinta(): void
    {
        $row = new AgileMaeprod([
            'prod_item_agile' => 'desc:carton-malo',
            'prod_descripcion_agile' => 'JARDIN CALABACITAS PACK DE 10 PLIEGOS DE CARTON FORRADO EN COLORES SURTIDOS',
            'prod_item' => '797271',
        ]);
        $maestro = new Maeprod([
            'prod_item' => '797271',
            'prod_nombre' => 'PACK CARTUCHO DE TINTA HP 670 4 COLORES',
        ]);

        $eval = $this->service->evaluarFila($row, $maestro, 20000);

        $this->assertSame('mala', $eval['estado']);
        $this->assertSame(AgileVinculoAuditoriaService::MOTIVO_SIN_SOLAPE, $eval['motivo']);
    }

    public function test_marca_mala_jeringa_vinculada_a_marcador(): void
    {
        $row = new AgileMaeprod([
            'prod_item_agile' => '39339377',
            'prod_descripcion_agile' => 'JERINGA DESECHABLE 50-60 ML PUNTA ROMA (REF:JEGOSNED0075) (NEDFLEX) (CAJA X 25 UN)',
            'prod_item' => '11043AZ',
        ]);
        $maestro = new Maeprod([
            'prod_item' => '11043AZ',
            'prod_nombre' => 'MARCADOR PERMANENTE BIC DESECHABLE PUNTA REDONDA AZUL',
        ]);

        $eval = $this->service->evaluarFila($row, $maestro, 20000);

        $this->assertSame('mala', $eval['estado']);
        $this->assertSame(AgileVinculoAuditoriaService::MOTIVO_SIN_SOLAPE, $eval['motivo']);
    }

    public function test_marca_ok_cuando_descripcion_y_maestro_coinciden(): void
    {
        $row = new AgileMaeprod([
            'prod_item_agile' => 'desc:carton-ok',
            'prod_descripcion_agile' => 'CARTON FORRADO PLIEGO COLORES SURTIDOS PACK 10',
            'prod_item' => 'CARTON01',
        ]);
        $maestro = new Maeprod([
            'prod_item' => 'CARTON01',
            'prod_nombre' => 'CARTON FORRADO PLIEGO COLORES SURTIDOS PACK 10',
        ]);

        $eval = $this->service->evaluarFila($row, $maestro, 20000);

        $this->assertSame('ok', $eval['estado']);
        $this->assertSame(AgileVinculoAuditoriaService::MOTIVO_OK, $eval['motivo']);
        $this->assertGreaterThanOrEqual(20000, $eval['score']);
    }

    public function test_maestro_inexistente_es_mala(): void
    {
        $row = new AgileMaeprod([
            'prod_item_agile' => 'desc:x',
            'prod_descripcion_agile' => 'ALGUNA DESCRIPCION CON TOKEN',
            'prod_item' => 'NOEXISTE',
        ]);

        $eval = $this->service->evaluarFila($row, null, 20000);

        $this->assertSame('mala', $eval['estado']);
        $this->assertSame(AgileVinculoAuditoriaService::MOTIVO_MAESTRO_INEXISTENTE, $eval['motivo']);
    }

    public function test_ids_malos_filtra_estado(): void
    {
        $auditoria = Collection::make([
            ['prod_item_agile' => 'a', 'estado' => 'ok'],
            ['prod_item_agile' => 'b', 'estado' => 'mala'],
            ['prod_item_agile' => 'c', 'estado' => 'mala'],
        ]);

        $this->assertSame(['b', 'c'], $this->service->idsMalos($auditoria));
    }
}

<?php

namespace Tests\Unit;

use App\Support\CotizacionListadoRetorno;
use Illuminate\Http\Request;
use Tests\TestCase;

class CotizacionListadoRetornoTest extends TestCase
{
    public function test_para_listado_omite_nronota_en_ruta_y_usa_buscar_nronota(): void
    {
        $q = CotizacionListadoRetorno::paraListado([
            'fechadesde' => '2026-01-01',
            'fechahasta' => '2026-08-01',
            'nronota' => 55,
            'cotizacion' => '',
            'estado_mp' => '',
            'orden_campo' => 'nronota',
            'orden_dir' => 'DESC',
        ], 3);

        $this->assertSame('listado', $q['from']);
        $this->assertSame('2026-01-01', $q['fechadesde']);
        $this->assertSame(55, $q['buscar_nronota']);
        $this->assertSame(3, $q['page']);
        $this->assertArrayNotHasKey('nronota', $q);
    }

    public function test_url_oportunidades(): void
    {
        $request = Request::create('/admin/cotizaciones/nueva', 'GET', [
            'from' => 'oportunidades',
            'codigo' => '1000-1-COT26',
            'op_page' => 4,
            'op_region' => '13',
            'op_codigo' => 'CA-FILTRO',
        ]);

        $url = CotizacionListadoRetorno::url($request);
        $this->assertStringContainsString(route('admin.oportunidades.para-cotizar.index', [], false), parse_url($url, PHP_URL_PATH) ?: '');
        $this->assertStringContainsString('op_page=4', $url);
        $this->assertStringContainsString('op_region=13', $url);
        $this->assertStringContainsString('op_codigo=CA-FILTRO', $url);
        $this->assertStringNotContainsString('codigo=1000', $url);
        $this->assertSame('Oportunidades', CotizacionListadoRetorno::label($request));
    }

    public function test_para_oportunidades_usa_prefijo_op(): void
    {
        $q = CotizacionListadoRetorno::paraOportunidades([
            'region' => '13',
            'codigo' => '1000-1-LE26',
            'cierre_24h' => true,
            'sort_column' => 'cierre',
            'sort_direction' => 'asc',
        ], 5);

        $this->assertSame('oportunidades', $q['from']);
        $this->assertSame('13', $q['op_region']);
        $this->assertSame('1000-1-LE26', $q['op_codigo']);
        $this->assertSame('1', $q['op_cierre_24h']);
        $this->assertSame(5, $q['op_page']);
        $this->assertArrayNotHasKey('codigo', $q);
        $this->assertArrayNotHasKey('page', $q);
    }

    public function test_url_adjudicadas_restaura_filtros(): void
    {
        $request = Request::create('/admin/cotizaciones/10', 'GET', [
            'from' => 'adjudicadas',
            'fechaentregadesde' => '2026-06-01',
            'fechaentregahasta' => '2026-06-30',
            'buscar_nronota' => 201,
            'page' => 2,
        ]);

        $url = CotizacionListadoRetorno::url($request);
        $this->assertStringContainsString(route('admin.cotizaciones.adjudicadas.index', [], false), parse_url($url, PHP_URL_PATH) ?: '');
        $this->assertStringContainsString('fechaentregadesde=2026-06-01', $url);
        $this->assertStringContainsString('nronota=201', $url);
        $this->assertStringContainsString('page=2', $url);
        $this->assertStringNotContainsString('buscar_nronota', $url);
    }
}

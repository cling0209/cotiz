<?php

namespace Tests\Unit;

use App\Support\CotizacionListadoRetorno;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CotizacionListadoRetornoComisionesTest extends TestCase
{
    #[Test]
    public function para_comisiones_arma_query_con_filtros_y_pagina(): void
    {
        $q = CotizacionListadoRetorno::paraComisiones([
            'fecha_envio_desde' => '2026-01-01',
            'fecha_envio_hasta' => '2026-01-31',
            'usuario' => 'jperez',
            'codigo_proceso' => '2923-1',
            'nronota' => 1234,
            'sort' => 'nronota',
            'dir' => 'asc',
            'por_pagina' => 40,
        ], 3);

        $this->assertSame('comisiones', $q['from']);
        $this->assertSame('2026-01-01', $q['fecha_envio_desde']);
        $this->assertSame('jperez', $q['usuario']);
        $this->assertSame(1234, $q['buscar_nronota']);
        $this->assertSame(3, $q['page']);
        $this->assertSame('40', $q['por_pagina']);
        $this->assertArrayNotHasKey('nronota', $q);
    }

    #[Test]
    public function url_y_label_desde_comisiones(): void
    {
        $request = Request::create('/admin/cotizaciones/1/edit', 'GET', [
            'from' => 'comisiones',
            'fecha_envio_desde' => '2026-02-01',
            'usuario' => 'admin',
            'page' => 2,
            'por_pagina' => 40,
        ]);

        $url = CotizacionListadoRetorno::url($request);
        $this->assertStringContainsString('compra-agil/resultados/comisiones', $url);
        $this->assertStringContainsString('fecha_envio_desde=2026-02-01', $url);
        $this->assertStringContainsString('usuario=admin', $url);
        $this->assertStringContainsString('page=2', $url);
        $this->assertSame('Comisiones', CotizacionListadoRetorno::label($request));
    }
}

<?php

namespace Tests\Unit;

use App\Services\OportunidadParaCotizarService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OportunidadParaCotizarHoyTest extends TestCase
{
    public function test_es_publicada_hoy_respeta_timezone(): void
    {
        config(['app.timezone' => 'America/Santiago']);
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00', 'America/Santiago'));

        $svc = $this->app->make(OportunidadParaCotizarService::class);

        $this->assertTrue($svc->esPublicadaHoy('2026-07-15T08:30:00-04:00'));
        $this->assertTrue($svc->esPublicadaHoy('2026-07-15'));
        $this->assertFalse($svc->esPublicadaHoy('2026-07-14T23:00:00-04:00'));
        $this->assertFalse($svc->esPublicadaHoy(''));
        $this->assertFalse($svc->esPublicadaHoy(null));

        Carbon::setTestNow();
    }

    public function test_fecha_ultimo_cambio_de_item_usa_cambio_y_cae_a_publicacion(): void
    {
        config(['app.timezone' => 'America/Santiago']);
        $svc = $this->app->make(OportunidadParaCotizarService::class);

        $conCambio = $svc->fechaUltimoCambioDeItem([
            'fechas' => [
                'fecha_publicacion' => '2026-08-21 12:30',
                'fecha_ultimo_cambio' => '2026-08-21 12:35',
            ],
        ]);
        $this->assertNotNull($conCambio);
        $this->assertSame('2026-08-21 12:35:00', $conCambio->format('Y-m-d H:i:s'));

        $soloPub = $svc->fechaUltimoCambioDeItem([
            'fechas' => [
                'fecha_publicacion' => '2026-08-21 12:30',
            ],
        ]);
        $this->assertNotNull($soloPub);
        $this->assertSame('2026-08-21 12:30:00', $soloPub->format('Y-m-d H:i:s'));

        $max = $svc->maxFechaUltimoCambioDeItems([
            ['fechas' => ['fecha_ultimo_cambio' => '2026-08-21 10:20']],
            ['fechas' => ['fecha_ultimo_cambio' => '2026-08-21 12:35']],
            ['nombre' => 'sin fechas'],
        ]);
        $this->assertNotNull($max);
        $this->assertSame('2026-08-21 12:35:00', $max->format('Y-m-d H:i:s'));

        $referencia = $svc->referenciaUltimoCambioDeItems([
            ['codigo' => '100-1-COT26', 'fechas' => ['fecha_ultimo_cambio' => '2026-08-21 10:20']],
            ['codigo' => '200-2-COT26', 'fechas' => ['fecha_ultimo_cambio' => '2026-08-21 12:35']],
        ]);
        $this->assertNotNull($referencia);
        $this->assertSame('200-2-COT26', $referencia['codigo']);
        $this->assertSame('2026-08-21 12:35:00', Carbon::parse($referencia['fecha'])->format('Y-m-d H:i:s'));
    }

    public function test_frase_debe_aparecer_en_nombre_u_organismo(): void
    {
        $svc = $this->app->make(OportunidadParaCotizarService::class);

        $this->assertTrue($svc->fraseApareceEnTexto('aseo', [
            'nombre' => 'Servicio de Aseo Industrial',
            'organismo' => 'Hospital',
        ]));

        $this->assertTrue($svc->fraseApareceEnTexto('servicio de aseo', [
            'nombre' => 'Contratación servicio de aseo 2026',
            'organismo' => '',
        ]));

        $this->assertFalse($svc->fraseApareceEnTexto('aseo', [
            'nombre' => 'Adquisición de Bomba Sumergible y Turbo Calefactor',
            'organismo' => 'Municipalidad',
        ]));

        $this->assertTrue($svc->fraseApareceEnTexto('papel bond', [
            'nombre' => 'Compra de papel y bond oficio',
            'organismo' => '',
        ]));

        // Subcadena dentro de otra palabra: no debe coincidir.
        $this->assertFalse($svc->fraseApareceEnTexto('MICAS', [
            'nombre' => 'ADJUNTAR ANEXO N°1 CAJAS TERMICAS Y TERMOGRAFOS',
            'organismo' => '',
        ]));

        // Plurales simples.
        $this->assertTrue($svc->fraseApareceEnTexto('RESMA', [
            'nombre' => 'Compra de resmas oficio',
            'organismo' => '',
        ]));
        $this->assertTrue($svc->fraseApareceEnTexto('RESMAS', [
            'nombre' => 'Adquisición resma bond',
            'organismo' => '',
        ]));
        $this->assertTrue($svc->fraseApareceEnTexto('lapiz', [
            'nombre' => 'Set de lápices grafito',
            'organismo' => '',
        ]));
    }

    public function test_parametros_region_incluyen_ventana_cambio_del_dia(): void
    {
        config(['app.timezone' => 'America/Santiago']);
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'America/Santiago'));

        $svc = $this->app->make(OportunidadParaCotizarService::class);
        $params = $svc->parametrosConsultaRegion(13, 1, '2026-07-14');

        $this->assertSame('publicada', $params['estado']);
        $this->assertSame(13, $params['region']);
        $this->assertArrayHasKey('cambio_desde', $params);
        $this->assertArrayHasKey('cambio_hasta', $params);
        $this->assertStringStartsWith('2026-07-14', $params['cambio_desde']);
        $this->assertStringStartsWith('2026-07-14', $params['cambio_hasta']);

        Carbon::setTestNow();
    }

    public function test_max_paginas_region_sigue_si_mercado_publico_trae_mas_de_20(): void
    {
        config(['cotiz.mercadopublico.oportunidad_max_paginas' => 200]);

        $this->assertSame(200, OportunidadParaCotizarService::maxPaginasRegion());
        $this->assertGreaterThan(20, OportunidadParaCotizarService::maxPaginasRegion());

        config(['cotiz.mercadopublico.oportunidad_max_paginas' => 8]);
        $this->assertSame(8, OportunidadParaCotizarService::maxPaginasRegion());
    }
}

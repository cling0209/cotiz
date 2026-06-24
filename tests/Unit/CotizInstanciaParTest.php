<?php

namespace Tests\Unit;

use App\Support\CotizInstanciaPar;
use Tests\TestCase;

class CotizInstanciaParTest extends TestCase
{
    public function test_resuelve_url_consulta_reicol_hacia_romulo(): void
    {
        config([
            'app.url' => 'https://cotiza.reicol.cl',
            'cotiz.api_nota.consulta_nro_cotizacion' => '',
        ]);

        $this->assertSame(
            'https://cotiza.romulo.cl/api/v1/nota-consulta',
            CotizInstanciaPar::urlConsultaEncargado(),
        );
        $this->assertTrue(CotizInstanciaPar::debeConsultarPar());
    }

    public function test_resuelve_url_consulta_romulo_hacia_reicol(): void
    {
        config([
            'app.url' => 'https://cotiza.romulo.cl',
            'cotiz.api_nota.consulta_nro_cotizacion' => '',
        ]);

        $this->assertSame(
            'https://cotiza.reicol.cl/api/v1/nota-consulta',
            CotizInstanciaPar::urlConsultaEncargado(),
        );
    }

    public function test_url_explicita_tiene_prioridad(): void
    {
        config([
            'app.url' => 'https://cotiza.reicol.cl',
            'cotiz.api_nota.consulta_nro_cotizacion' => 'https://custom.test/api/v1/nota-consulta',
        ]);

        $this->assertSame('https://custom.test/api/v1/nota-consulta', CotizInstanciaPar::urlConsultaEncargado());
    }

    public function test_url_explicita_al_propio_host_se_ignora_y_usa_par(): void
    {
        config([
            'app.url' => 'https://cotiza.reicol.cl',
            'cotiz.api_nota.consulta_nro_cotizacion' => 'https://cotiza.reicol.cl/api/v1/nota-consulta',
        ]);

        $this->assertSame(
            'https://cotiza.romulo.cl/api/v1/nota-consulta',
            CotizInstanciaPar::urlConsultaEncargado(),
        );
        $this->assertTrue(CotizInstanciaPar::debeConsultarPar());
    }

    public function test_romulo_con_consulta_propia_usa_reicol(): void
    {
        config([
            'app.url' => 'https://cotiza.romulo.cl',
            'cotiz.api_nota.consulta_nro_cotizacion' => 'https://cotiza.romulo.cl/api/v1/nota-consulta',
        ]);

        $this->assertSame(
            'https://cotiza.reicol.cl/api/v1/nota-consulta',
            CotizInstanciaPar::urlConsultaEncargado(),
        );
    }

    public function test_localhost_no_consulta_par(): void
    {
        config([
            'app.url' => 'http://localhost:8082',
            'cotiz.api_nota.consulta_nro_cotizacion' => '',
        ]);

        $this->assertSame('', CotizInstanciaPar::urlConsultaEncargado());
        $this->assertFalse(CotizInstanciaPar::debeConsultarPar());
    }
}

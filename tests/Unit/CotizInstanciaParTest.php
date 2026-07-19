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
            'cotiz.sistema' => 'Reicol',
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
            'cotiz.sistema' => 'Romulo',
            'cotiz.api_nota.consulta_nro_cotizacion' => '',
        ]);

        $this->assertSame(
            'https://cotiza.reicol.cl/api/v1/nota-consulta',
            CotizInstanciaPar::urlConsultaEncargado(),
        );
        $this->assertSame('https://cotiza.reicol.cl/up', CotizInstanciaPar::urlDespertarSitioPar());
        $this->assertSame('https://cotiza.reicol.cl/admin/login', CotizInstanciaPar::urlLoginSitioPar());
    }

    public function test_url_explicita_tiene_prioridad(): void
    {
        config([
            'app.url' => 'https://cotiza.reicol.cl',
            'cotiz.sistema' => 'Reicol',
            'cotiz.api_nota.consulta_nro_cotizacion' => 'https://custom.test/api/v1/nota-consulta',
        ]);

        $this->assertSame('https://custom.test/api/v1/nota-consulta', CotizInstanciaPar::urlConsultaEncargado());
    }

    public function test_url_explicita_al_propio_host_se_ignora_y_usa_par(): void
    {
        config([
            'app.url' => 'https://cotiza.reicol.cl',
            'cotiz.sistema' => 'Reicol',
            'cotiz.api_nota.consulta_nro_cotizacion' => 'https://cotiza.reicol.cl/api/v1/nota-consulta',
        ]);

        $this->assertSame(
            'https://cotiza.romulo.cl/api/v1/nota-consulta',
            CotizInstanciaPar::urlConsultaEncargado(),
        );
        $this->assertTrue(CotizInstanciaPar::debeConsultarPar());

        $resolucion = CotizInstanciaPar::resolucionUrlConsulta();
        $this->assertSame('https://cotiza.reicol.cl/api/v1/nota-consulta', $resolucion['url_env']);
        $this->assertSame('https://cotiza.romulo.cl/api/v1/nota-consulta', $resolucion['url_utilizada']);
        $this->assertStringContainsString('mismo sitio', (string) $resolucion['nota_url']);
    }

    public function test_romulo_con_consulta_propia_usa_reicol(): void
    {
        config([
            'app.url' => 'https://cotiza.romulo.cl',
            'cotiz.sistema' => 'Romulo',
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
            'cotiz.sistema' => 'Cotiz',
            'cotiz.api_nota.consulta_nro_cotizacion' => '',
        ]);

        $this->assertSame('', CotizInstanciaPar::urlConsultaEncargado());
        $this->assertFalse(CotizInstanciaPar::debeConsultarPar());
        $this->assertFalse(CotizInstanciaPar::debeExigirConsultaPar());
    }

    public function test_sistema_romulo_resuelve_par_sin_dominio_canonico(): void
    {
        config([
            'app.url' => 'https://cotiz-app.onrender.com',
            'cotiz.sistema' => 'Romulo',
            'cotiz.api_nota.consulta_nro_cotizacion' => '',
        ]);

        $this->assertSame(
            'https://cotiza.reicol.cl/api/v1/nota-consulta',
            CotizInstanciaPar::urlConsultaEncargado(),
        );
        $this->assertTrue(CotizInstanciaPar::debeConsultarPar());
        $this->assertTrue(CotizInstanciaPar::debeExigirConsultaPar());
    }

    public function test_sistema_reicol_resuelve_par_sin_dominio_canonico(): void
    {
        config([
            'app.url' => 'https://cotiz-app.onrender.com',
            'cotiz.sistema' => 'Reicol',
            'cotiz.api_nota.consulta_nro_cotizacion' => '',
        ]);

        $this->assertSame(
            'https://cotiza.romulo.cl/api/v1/nota-consulta',
            CotizInstanciaPar::urlConsultaEncargado(),
        );
    }

    public function test_reicol_con_app_url_romulo_usa_env_hacia_romulo(): void
    {
        config([
            'app.url' => 'https://cotiza.romulo.cl',
            'cotiz.sistema' => 'Reicol',
            'cotiz.api_nota.consulta_nro_cotizacion' => 'https://cotiza.romulo.cl/api/v1/nota-consulta',
        ]);

        $this->assertSame('cotiza.reicol.cl', CotizInstanciaPar::hostLocal());
        $this->assertSame(
            'https://cotiza.romulo.cl/api/v1/nota-consulta',
            CotizInstanciaPar::urlConsultaEncargado(),
        );

        $resolucion = CotizInstanciaPar::resolucionUrlConsulta();
        $this->assertSame($resolucion['url_env'], $resolucion['url_utilizada']);
        $this->assertNull($resolucion['nota_url']);
    }
}

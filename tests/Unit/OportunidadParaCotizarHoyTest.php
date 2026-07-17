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
}

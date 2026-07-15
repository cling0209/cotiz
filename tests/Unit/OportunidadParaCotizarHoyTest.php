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
}

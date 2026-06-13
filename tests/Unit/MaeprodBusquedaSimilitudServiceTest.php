<?php

namespace Tests\Unit;

use App\Services\MaeprodBusquedaSimilitudService;
use Tests\TestCase;

class MaeprodBusquedaSimilitudServiceTest extends TestCase
{
    private MaeprodBusquedaSimilitudService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MaeprodBusquedaSimilitudService;
    }

    public function test_normaliza_texto_y_extrae_tokens(): void
    {
        $norm = $this->service->normalizarTexto('papel bond 75gr a4!');
        $this->assertSame('PAPEL BOND 75GR A4', $norm);

        $tokens = $this->service->extraerTokens($norm);
        $this->assertContains('PAPEL', $tokens);
        $this->assertContains('BOND', $tokens);
        $this->assertContains('75', $tokens);
        $this->assertContains('4', $tokens);
    }

    public function test_nt1_genera_token_numerico(): void
    {
        $tokens = $this->service->extraerTokens('PRODUCTO NT1 DEMO');
        $this->assertContains('1', $tokens);
        $this->assertNotContains('NT', $tokens);
    }

    public function test_puntaje_favorece_coincidencia_por_tokens(): void
    {
        $mejor = $this->service->scoreSimilitudFila(
            'papel bond 75 gr a4',
            'DEMO001',
            'PRODUCTO DEMO PAPEL BOND A4'
        );
        $peor = $this->service->scoreSimilitudFila(
            'papel bond 75 gr a4',
            'DEMO003',
            'PRODUCTO DEMO LAPIZ GRAFITO'
        );

        $this->assertGreaterThan($peor, $mejor);
    }

    public function test_codigo_exacto_tiene_maximo_puntaje(): void
    {
        $exacto = $this->service->scoreSimilitudFila('DEMO001', 'DEMO001', 'OTRO NOMBRE');
        $parcial = $this->service->scoreSimilitudFila('papel bond', 'DEMO001', 'PRODUCTO DEMO PAPEL BOND A4');

        $this->assertGreaterThan($parcial, $exacto);
    }
}

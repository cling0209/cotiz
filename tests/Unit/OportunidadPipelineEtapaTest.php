<?php

namespace Tests\Unit;

use App\Support\OportunidadPipelineEtapa;
use PHPUnit\Framework\TestCase;

class OportunidadPipelineEtapaTest extends TestCase
{
    public function test_ultimos_pasos_del_plan_toma_los_ultimos_cinco(): void
    {
        $pasos = [];
        for ($i = 1; $i <= 7; $i++) {
            $pasos[] = [
                'codigo' => 'COD'.$i,
                'estado' => 'ok',
                'fin' => '2026-08-22T10:0'.$i.':00+00:00',
            ];
        }

        $recientes = OportunidadPipelineEtapa::ultimosPasosDelPlan($pasos);

        $this->assertCount(5, $recientes);
        $this->assertSame('COD3', $recientes[0]['codigo']);
        $this->assertSame('COD7', $recientes[4]['codigo']);
    }

    public function test_cierre_busqueda_incluye_siguiente_vinculo(): void
    {
        $cierre = OportunidadPipelineEtapa::cierreBusqueda(0);

        $this->assertTrue($cierre['sin_mas_automatico']);
        $this->assertSame(OportunidadPipelineEtapa::VINCULO, $cierre['siguiente_proceso']);
        $this->assertStringContainsString('Vinculaciones internas', $cierre['siguiente_proceso_label']);
        $this->assertStringContainsString('Búsqueda terminada', $cierre['mensaje']);
    }

    public function test_cierre_vinculo_incluye_siguiente_adjuntos(): void
    {
        $cierre = OportunidadPipelineEtapa::cierreVinculo(2);

        $this->assertTrue($cierre['sin_mas_automatico']);
        $this->assertSame(OportunidadPipelineEtapa::ADJUNTOS, $cierre['siguiente_proceso']);
        $this->assertStringContainsString('Adjuntos Mercado Público', $cierre['siguiente_proceso_label']);
        $this->assertStringContainsString('2', $cierre['mensaje']);
    }

    public function test_resolver_activo_prioriza_busqueda(): void
    {
        $activo = OportunidadPipelineEtapa::resolverActivo(
            ['estado' => 'running', 'total_pasos' => 10, 'pasos_procesados' => 3],
            ['estado' => 'running'],
            ['estado' => 'running'],
        );

        $this->assertNotNull($activo);
        $this->assertSame(OportunidadPipelineEtapa::BUSQUEDA, $activo['proceso']);
        $this->assertSame('3/10', $activo['detalle']);
    }
}

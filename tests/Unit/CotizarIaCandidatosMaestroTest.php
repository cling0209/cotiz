<?php

namespace Tests\Unit;

use App\Models\Maeprod;
use App\Services\CotizarIaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class CotizarIaCandidatosMaestroTest extends TestCase
{
    use RefreshDatabase;

    private function invocar(CotizarIaService $service, string $metodo, mixed ...$args): mixed
    {
        $ref = new ReflectionClass($service);
        $m = $ref->getMethod($metodo);
        $m->setAccessible(true);

        return $m->invoke($service, ...$args);
    }

    public function test_terminos_busqueda_maestro_prioriza_terminos_cortos_sobre_descripcion_larga(): void
    {
        $desc = 'SET ACRILICOS 12 COLORES NEON Y PASTEL 12 ML';
        $terminos = $this->invocar(app(CotizarIaService::class), 'terminosBusquedaMaestro', $desc);

        $this->assertNotSame([], $terminos);
        $this->assertSame($desc, $terminos[array_key_last($terminos)]);
        $this->assertTrue(mb_strlen($terminos[0]) < mb_strlen($desc));
    }

    public function test_candidatos_unen_terminos_sin_cortar_por_primer_termino(): void
    {
        $desc = 'SET ACRILICOS 12 COLORES NEON Y PASTEL 12 ML';
        for ($n = 1; $n <= 18; $n++) {
            Maeprod::query()->create([
                'prod_item' => 'DEST'.$n,
                'prod_nombre' => 'DESTACADORES NEON PASTEL SET 12 COLORES NUOVO '.$n,
                'prod_valor' => 1000 + $n,
                'prod_valor_costo' => 800,
                'prod_familia' => 'LIBR',
            ]);
        }
        Maeprod::query()->create([
            'prod_item' => '25000005',
            'prod_nombre' => 'SET ACRILICOS ARTEL 12 COLORES DE 12ML',
            'prod_valor' => 3745,
            'prod_valor_costo' => 2800,
            'prod_familia' => 'LIBR',
        ]);

        config([
            'cotiz.cotizar_ia.candidatos_por_linea' => 20,
            'cotiz.cotizar_ia.candidatos_por_termino' => 12,
            'cotiz.cotizar_ia.busqueda_max_terminos' => 6,
        ]);

        $service = app(CotizarIaService::class);
        $terminos = $this->invocar($service, 'terminosBusquedaMaestro', $desc);
        $candidatos = $this->invocar($service, 'candidatosMaeprod', $desc, $terminos);

        $this->assertArrayHasKey('25000005', $candidatos);
        $this->assertGreaterThanOrEqual(1, count($candidatos));
    }
}

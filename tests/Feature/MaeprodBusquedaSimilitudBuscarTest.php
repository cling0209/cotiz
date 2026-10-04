<?php

namespace Tests\Feature;

use App\Models\Maeprod;
use App\Services\MaeprodBusquedaSimilitudService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaeprodBusquedaSimilitudBuscarTest extends TestCase
{
    use RefreshDatabase;

    public function test_separadores_vinilicos_encuentra_separador_vinil_antes_que_carpetas_oficio_colores(): void
    {
        $productos = [
            ['355241', 'SEPARADOR OFICIO VINIL LAVORO 6 POSICIONES'],
            ['LIBR1065', 'CARPETA CARTULINA PIG DIFERNTES COLORES OFICIO'],
            ['SOBRBEI001', 'SOBRE OFICIO C/ BROCHE DIF. COLORES OFICIO (12-600'],
            ['3113', 'CARTULINA / OPALINA 230GR MIX 5 COLORES TAMANO OFICIO'],
            ['33025', 'TABLA APRETAPAPEL TORRE OFICIO APRETADOR PS MIX COLORES'],
            ['3872001', '6 PLIEGOS GOMA EVA FLUOR DIFERENTES COLORES LAVORO'],
            ['25627', 'ARCHIVADOR COLON OFICIO ANCHO COLORES SURTIDOS'],
            ['100545-CS', 'CARPETA C/ELASTICO CARTULINA OFICIO COLORES SURTIDOS'],
        ];
        foreach ($productos as [$item, $nombre]) {
            Maeprod::query()->create([
                'prod_item' => $item,
                'prod_nombre' => $nombre,
                'prod_valor' => 713,
                'prod_valor_costo' => 549,
                'prod_familia' => 'VARIOS',
            ]);
        }

        $filas = app(MaeprodBusquedaSimilitudService::class)->buscar(
            'SEPARADORES COLORES OFICIO TIPO LAVORO O TORRE O ARTESANO (SET 6 COLORES) VINILICOS',
            null,
            3,
        );

        $this->assertSame('355241', trim((string) $filas->first()?->prod_item));
    }

    public function test_set_geometrico_encuentra_set_reglas_de_30cm_en_el_maestro(): void
    {
        $productos = [
            ['REGLHOL005', 'SET REGLAS ACRILICAS 30CM 4 PCS #18160711(100-200)'],
            ['REGLHOL0051', 'SET REGLAS ACRILICAS 30CM 4PCS'],
            ['REGLBEI003', 'SET REGLAS GEOMETRICO 4 PCS.20CM/2ESCUAD(1-100-200)'],
            ['MERLIN0656', 'SET REGLAS PLASTICAS 13 PZAS ENCUADERNACION WORK ON A HOBBY COLOR SURTIDO'],
            ['OTROGRANDE', 'ESTUCHE GRANDE PARA LAPICES 50 UNIDADES'],
        ];
        foreach ($productos as [$item, $nombre]) {
            Maeprod::query()->create([
                'prod_item' => $item,
                'prod_nombre' => $nombre,
                'prod_valor' => 1990,
                'prod_valor_costo' => 1200,
                'prod_familia' => 'LIBR',
            ]);
        }

        $filas = app(MaeprodBusquedaSimilitudService::class)->buscar(
            'SET GEOMETRICO GRANDE 30 CM 04 U',
            null,
            15,
        );
        $items = $filas->map(fn ($fila) => trim((string) $fila->prod_item))->all();

        $this->assertContains('REGLHOL005', $items);
        $this->assertContains('REGLHOL0051', $items);
        if (in_array('OTROGRANDE', $items, true)) {
            $this->assertTrue(
                array_search('REGLHOL005', $items, true) < array_search('OTROGRANDE', $items, true)
            );
        }
    }
}

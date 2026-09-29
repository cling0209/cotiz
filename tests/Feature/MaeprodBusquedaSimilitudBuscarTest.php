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
}

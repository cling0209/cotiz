<?php

namespace Tests\Unit;

use App\Support\AgileDescripcion;
use PHPUnit\Framework\TestCase;

class AgileDescripcionTest extends TestCase
{
    public function test_para_maeprod_trunca_a_255_caracteres(): void
    {
        $largo = str_repeat('A', 300);
        $resultado = AgileDescripcion::paraMaeprod($largo);

        $this->assertNotNull($resultado);
        $this->assertSame(255, mb_strlen($resultado));
        $this->assertSame(str_repeat('A', 255), $resultado);
    }

    public function test_para_detalle_trunca_a_500_caracteres(): void
    {
        $largo = str_repeat('B', 600);
        $resultado = AgileDescripcion::paraDetalle($largo);

        $this->assertNotNull($resultado);
        $this->assertSame(500, mb_strlen($resultado));
    }

    public function test_normaliza_comillas_simples(): void
    {
        $this->assertSame('café ´rico', AgileDescripcion::paraMaeprod("café 'rico"));
    }

    public function test_vacio_devuelve_null(): void
    {
        $this->assertNull(AgileDescripcion::paraMaeprod('   '));
        $this->assertNull(AgileDescripcion::paraDetalle(''));
    }
}

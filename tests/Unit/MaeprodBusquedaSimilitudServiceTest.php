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

    public function test_plural_cajas_genera_variante_caja(): void
    {
        $variantes = $this->service->tokenVariantes('CAJAS');
        $this->assertContains('CAJA', $variantes);
    }

    public function test_stopword_para_se_filtra_en_tokens_sql(): void
    {
        $tokens = $this->service->tokensConsultaSql(
            $this->service->extraerTokens('CAJAS PARA ARCHIVO MEGABOX')
        );
        $this->assertNotContains('PARA', $tokens);
        $this->assertContains('MEGABOX', $tokens);
    }

    public function test_megabox_memphis_puntaje_supera_juguete_ruidoso(): void
    {
        $megabox = $this->service->scoreSimilitudFila(
            'CAJAS MEGABOX MEMPHIS (CAJAS PARA 6 ARCHIVOS) 2',
            'U438742',
            'CAJA ARCHIVO MEGABOX - MEMPHIS'
        );
        $juguete = $this->service->scoreSimilitudFila(
            'CAJAS MEGABOX MEMPHIS (CAJAS PARA 6 ARCHIVOS) 2',
            'JUEGFUN063',
            'SET DE COMIDA PARA PICNIC 63 PIEZAS #7020'
        );

        $this->assertGreaterThan($juguete, $megabox);
    }

    public function test_codigo_exacto_tiene_maximo_puntaje(): void
    {
        $exacto = $this->service->scoreSimilitudFila('DEMO001', 'DEMO001', 'OTRO NOMBRE');
        $parcial = $this->service->scoreSimilitudFila('papel bond', 'DEMO001', 'PRODUCTO DEMO PAPEL BOND A4');

        $this->assertGreaterThan($parcial, $exacto);
    }

    public function test_puno_lenci_no_matchea_goma_eva_por_tokens_genericos(): void
    {
        $consulta = 'PACK DE PLIEGOS DE PAÑO LENCI DE 10 COLORES SURTIDOS 1MT X 90CM';
        $malo = 'GOMA EVA OFFIONE SURTIDO 20X30 CM PAQUETE DE 10 UNIDADES';
        $bueno = 'PAÑO LENCI PLIEGOS 90X100 CM COLORES SURTIDOS PACK 10';

        $this->assertFalse($this->service->tieneSolapeDistintivo($consulta, $malo));
        $this->assertTrue($this->service->tieneSolapeDistintivo($consulta, $bueno));

        $scoreMalo = $this->service->scoreSimilitudFila($consulta, '56841S', $malo);
        $scoreBueno = $this->service->scoreSimilitudFila($consulta, 'LENCI01', $bueno);

        $this->assertLessThan(5000, $scoreMalo);
        $this->assertGreaterThan($scoreMalo, $scoreBueno);
    }

    public function test_carton_forrado_no_matchea_cartucho_tinta_hp(): void
    {
        $consulta = 'JARDIN CALABACITAS PACK DE 10 PLIEGOS DE CARTON FORRADO EN COLORES SURTIDOS';
        $malo = 'PACK CARTUCHO DE TINTA HP 670 4 COLORES';

        $this->assertFalse($this->service->tieneSolapeDistintivo($consulta, $malo));
        $this->assertLessThan(
            5000,
            $this->service->scoreSimilitudFila($consulta, '797271', $malo)
        );
    }

    public function test_familias_producto_detecta_por_inicio_de_palabra(): void
    {
        $this->assertContains('CLIP', $this->service->familiasProducto('CLIP ACCOCLIP METAL 25 UNIDADES'));
        $this->assertContains('ACCOCLIP', $this->service->familiasProducto('CLIP ACCOCLIP METAL 25 UNIDADES'));
        $this->assertContains('PORTAMINAS', $this->service->familiasProducto('PORTAMINAS 0.5 MM COLORES'));

        // CLIP no debe activarse dentro de ACCOCLIP (evita match a mitad de palabra).
        $familias = $this->service->familiasProducto('ACCOCLIP OFICIO CAJA');
        $this->assertContains('ACCOCLIP', $familias);
        $this->assertNotContains('CLIP', $familias);
    }

    public function test_clip_no_matchea_portaminas_por_familia(): void
    {
        $this->assertTrue(
            $this->service->hayConflictoFamilia('CLIP ACCOCLIP METAL', 'PORTAMINAS 0.5 MM')
        );
        $this->assertFalse(
            $this->service->tieneSolapeDistintivo('CLIP ACCOCLIP METAL 25 UNIDADES', 'PORTAMINAS 0.5 MM PUNTA METAL')
        );
    }

    public function test_cartulina_no_matchea_destacador_por_familia(): void
    {
        $this->assertTrue(
            $this->service->hayConflictoFamilia('CARTULINA ESPAÑOLA COLORES SURTIDOS', 'DESTACADOR TEXMARKET COLORES')
        );
        $this->assertFalse(
            $this->service->tieneSolapeDistintivo('CARTULINA ESPAÑOLA COLORES SURTIDOS', 'DESTACADOR TEXMARKET 4 COLORES')
        );
    }

    public function test_sin_familia_no_marca_conflicto(): void
    {
        // GREDAS no tiene familia definida: no debe bloquear un match legítimo.
        $this->assertFalse(
            $this->service->hayConflictoFamilia('GREDAS ESCOLARES DE 1 KILO', 'GREDAS ESCOLARES 1 KG')
        );
        $this->assertTrue(
            $this->service->tieneSolapeDistintivo('GREDAS ESCOLARES DE 1 KILO', 'GREDAS ESCOLARES 1 KG')
        );
    }

    public function test_misma_familia_no_marca_conflicto(): void
    {
        $this->assertFalse(
            $this->service->hayConflictoFamilia('CINTA MASKING 24 MM', 'CINTA ADHESIVA TRANSPARENTE 24 MM')
        );
    }
}

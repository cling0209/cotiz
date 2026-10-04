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

    public function test_variantes_incluyen_singular_es_y_raiz_de_adjetivo(): void
    {
        $this->assertContains('SEPARADOR', $this->service->tokenVariantes('SEPARADORES'));
        $this->assertContains('VINIL', $this->service->tokenVariantes('VINILICOS'));
        $this->assertContains('METAL', $this->service->tokenVariantes('METALICA'));
        $this->assertNotContains('MES', $this->service->tokenVariantes('MESES'));
        $this->assertContains('PERFORADOR', $this->service->tokenVariantes('PERFORADORA'));
        $this->assertNotContains('CAJ', $this->service->tokenVariantes('CAJAS'));
    }

    public function test_normaliza_sin_tildes_y_payload_incluye_numeros_de_dos_digitos(): void
    {
        $this->assertSame('PERFORADORA 40 HOJAS TIPO COLON', $this->service->normalizarTexto('Perforadora 40 hojas Tipo Colón'));
        $this->assertSame('PAÑO LENCI', $this->service->normalizarTexto('Paño Lenci'));

        [, $tokens] = $this->service->parsearPayloadSimilitud(
            $this->service->codificarPayloadBuscarSimilitud('Perforadora grande 40 hojas Tipo Colón')
        );
        $this->assertContains('40', $tokens);
        $this->assertContains('COLON', $tokens);
        $this->assertSame('40', end($tokens));
    }

    public function test_separadores_vinilicos_tiene_solape_con_separador_vinil(): void
    {
        $consulta = 'SEPARADORES COLORES OFICIO TIPO LAVORO O TORRE O ARTESANO (SET 6 COLORES) VINILICOS';

        $this->assertTrue($this->service->tieneSolapeDistintivo($consulta, 'SEPARADOR OFICIO VINIL LAVORO 6 POSICIONES'));
        $this->assertTrue($this->service->pasaFiltrosAtributos($consulta, 'SEPARADOR OFICIO VINIL LAVORO 6 POSICIONES'));
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

    public function test_cinta_no_cae_dentro_de_cartulina(): void
    {
        $this->assertFalse(
            $this->service->tokenVarianteCaeEnTexto('CINTA', 'CARTULINA ESPANOLA COLORES')
        );
        $this->assertTrue(
            $this->service->tokenVarianteCaeEnTexto('CINTA', 'CINTA MASKING 24 MM')
        );
    }

    public function test_medidas_21g_no_equivale_a_40g(): void
    {
        $this->assertFalse(
            $this->service->medidasCompatibles('ADHESIVO BARRA 21 G', 'ADHESIVO BARRA 40 G')
        );
        $this->assertTrue(
            $this->service->medidasCompatibles('ADHESIVO BARRA 21 G', 'ADHESIVO EN BARRA 21G FABER')
        );
        $this->assertTrue(
            $this->service->medidasCompatibles('GREDAS 1 KILO', 'GREDAS ESCOLARES 1 KG')
        );
    }

    public function test_diferencia_medida_relativa_y_texto_para_aviso(): void
    {
        $this->assertSame(0.0, $this->service->diferenciaMedida('TAMPON 70 MM', 'TAMPON HAND 70MM'));
        $this->assertEqualsWithDelta(5 / 70, $this->service->diferenciaMedida('TAMPON 70 MM', 'TAMPON REYSOL 65 MM'), 1e-9);
        $this->assertEqualsWithDelta(0.5, $this->service->diferenciaMedida('CINTA 1 CM', 'CINTA 5 MM'), 1e-9);
        $this->assertNull($this->service->diferenciaMedida('TAMPON 70 MM', 'TAMPON DACTILAR NEGRO'));
        $this->assertSame(1.0, $this->service->diferenciaMedida('PAPEL CARTA', 'PAPEL OFICIO'));
        $this->assertSame(['70 MM'], $this->service->medidasTexto('Tampón huella 70 mm'));
    }

    public function test_busqueda_conserva_decimal_y_clave_de_frases_no_cambia(): void
    {
        $this->assertSame('MINA 0.5 MM HB TUBO 12 UNIDADES', $this->service->normalizarBusqueda('Mina 0,5 mm HB (Tubo 12 unidades)'));
        $this->assertSame('CAJA 1000 ML', $this->service->normalizarBusqueda('Caja 1.000 ml.'));
        $this->assertSame('MINA 0 5 MM HB', $this->service->normalizarTexto('MINA 0,5 MM HB'));

        $this->assertSame(['0.5 MM'], $this->service->medidasTexto('MINA 0,5 MM HB'));
        $this->assertSame(0.0, $this->service->diferenciaMedida('MINA 0,5 MM HB', 'MINAS 0.5 MM HB'));
        $this->assertFalse($this->service->medidasCompatibles('MINA 0,5 MM', 'MINA 5 MM'));
    }

    public function test_payload_incluye_decimal_y_codigo_corto(): void
    {
        [, $tokens] = $this->service->parsearPayloadSimilitud(
            $this->service->codificarPayloadBuscarSimilitud('MINA 0,5 MM HB (Tubo 12 unidades)')
        );

        $this->assertContains('MINA', $tokens);
        $this->assertContains('0.5', $tokens);
        $this->assertContains('HB', $tokens);
        $this->assertNotContains('MM', $tokens);
        $this->assertSame('HB', end($tokens));
    }

    public function test_busqueda_equivalencias_config_liga_geometrico_con_reglas(): void
    {
        $consulta = 'SET GEOMETRICO GRANDE 30 CM 04 U';
        $maestro = 'SET REGLAS ACRILICAS 30CM 4 PCS';

        $this->assertContains('REGLA', $this->service->familiasProducto($consulta));
        $this->assertFalse($this->service->hayConflictoFamilia($consulta, $maestro));
        $this->assertTrue($this->service->tieneSolapeDistintivo($consulta, $maestro));
        $this->assertContains('REGLA', $this->service->tokenVariantes('GEOMETRICO'));
        $alts = $this->service->terminosSinonimos($consulta);
        $this->assertTrue(collect($alts)->contains(fn (string $t) => str_contains($t, 'REGLAS') && ! str_contains($t, 'GEOMETRICO')));
        $this->assertTrue(collect($alts)->contains(fn (string $t) => ! str_contains($t, 'GRANDE')));
    }

    public function test_set_acrilicos_no_confunde_con_destacadores(): void
    {
        $consulta = 'SET ACRILICOS 12 COLORES NEON Y PASTEL 12 ML';

        $this->assertContains('PINTURA_ACRILICA', $this->service->familiasProducto($consulta));
        $this->assertTrue($this->service->hayConflictoFamilia($consulta, 'DESTACADORES NEON PASTEL SET 12 COLORES NUOVO'));
        $this->assertFalse($this->service->hayConflictoFamilia($consulta, 'SET ACRILICOS ARTEL 12 COLORES DE 12ML'));
        $vendedor = $this->service->terminosBusquedaVendedor($consulta);
        $this->assertNotSame([], $vendedor);
        $this->assertTrue(collect($vendedor)->contains(fn (string $t) => str_contains($t, 'ACRILICOS')));
    }

    public function test_goma_eva_carpeta_empaque_no_confunde_con_carpeta_archivador(): void
    {
        $mp = 'GOMA EVA ADHESIVA FLUOR CARPETA 020X030 CM 06 U COLOR SURTIDOS';
        $familias = $this->service->familiasProducto($mp);

        $this->assertContains('GOMA_EVA', $familias);
        $this->assertNotContains('CARPETA', $familias);
        $this->assertFalse($this->service->hayConflictoFamilia($mp, 'GOMA EVA FLUOR'));
        $this->assertTrue($this->service->tieneSolapeDistintivo($mp, 'GOMA EVA FLUOR'));
        $this->assertTrue(collect($this->service->terminosSinonimos('GOMA EVA FLUOR CARPETA'))->isNotEmpty());
    }

    public function test_carpeta_archivador_sigue_detectando_familia_carpeta(): void
    {
        $this->assertContains('CARPETA', $this->service->familiasProducto('CARPETA ARCHIVADOR OFICIO LOMO ANCHO'));
        $this->assertNotContains('GOMA_EVA', $this->service->familiasProducto('CARPETA ARCHIVADOR OFICIO LOMO ANCHO'));
    }

    public function test_busqueda_equivalencias_grupo_extra_desde_config(): void
    {
        config([
            'cotiz.busqueda_equivalencias' => [
                [
                    'familia' => 'FOAMI',
                    'terminos' => ['FOAMI', 'GOMA', 'EVA'],
                ],
            ],
        ]);
        $service = new MaeprodBusquedaSimilitudService;

        $this->assertSame(['GOMA', 'EVA'], $service->equivalentesDeToken('FOAMI'));
        $alts = $service->terminosSinonimos('PLIEGO GOMA EVA 20X30');
        $this->assertTrue(collect($alts)->contains(fn (string $t) => str_contains($t, 'FOAMI')));
        $this->assertFalse($service->hayConflictoFamilia('PLIEGO GOMA EVA', 'FOAMI OFFIONE 20X30'));
    }

    public function test_elige_el_mas_economico(): void
    {
        $elegido = $this->service->elegirMasEconomico([
            [
                'prod_item' => 'CARO',
                'prod_nombre' => 'ADHESIVO BARRA 21 G',
                'prod_valor' => 900,
                'prod_valor_costo' => 700,
            ],
            [
                'prod_item' => 'BARATO',
                'prod_nombre' => 'ADHESIVO BARRA 21 G OFERTA',
                'prod_valor' => 500,
                'prod_valor_costo' => 300,
            ],
        ]);

        $this->assertSame('BARATO', $elegido['prod_item'] ?? null);
    }

    public function test_sin_costo_compara_con_el_precio_descontado_el_factor_metropolitana(): void
    {
        config(['cotiz.factor_precio_venta_rm' => 1.22]);

        $elegido = $this->service->elegirMasEconomico([
            ['prod_item' => 'CON_COSTO', 'prod_nombre' => 'X', 'prod_valor' => 1400, 'prod_valor_costo' => 1100],
            ['prod_item' => 'SIN_COSTO', 'prod_nombre' => 'X', 'prod_valor' => 1220, 'prod_valor_costo' => 0],
        ]);

        $this->assertSame('SIN_COSTO', $elegido['prod_item'] ?? null);
        $this->assertSame(1000, $this->service->costoPropuesta(1220, 0));
        $this->assertSame(11475, $this->service->costoPropuesta(14000, 0));
    }
}

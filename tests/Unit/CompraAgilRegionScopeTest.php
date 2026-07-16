<?php

namespace Tests\Unit;

use App\Services\CompraAgilRegionScope;
use Tests\TestCase;

class CompraAgilRegionScopeTest extends TestCase
{
    public function test_regiones_incluidas_desde_config(): void
    {
        config([
            'cotiz.mercadopublico.regiones' => [1, 13, 16],
        ]);

        $incluidas = CompraAgilRegionScope::regionesIncluidas();

        $this->assertSame([1, 13, 16], $incluidas);
    }

    public function test_indice_en_config_respeta_orden_env(): void
    {
        config(['cotiz.mercadopublico.regiones' => [13, 5, 6]]);

        $this->assertSame(0, CompraAgilRegionScope::indiceEnConfig(13));
        $this->assertSame(1, CompraAgilRegionScope::indiceEnConfig(5));
        $this->assertSame(2, CompraAgilRegionScope::indiceEnConfig(6));
        $this->assertSame(999, CompraAgilRegionScope::indiceEnConfig(99));
        $this->assertSame(999, CompraAgilRegionScope::indiceEnConfig(null));
    }

    public function test_excluye_region_no_configurada(): void
    {
        config(['cotiz.mercadopublico.regiones' => [13, 5]]);

        $this->assertTrue(CompraAgilRegionScope::debeExcluirItem([
            'institucion' => ['region' => 12, 'organismo_comprador' => 'Hospital Punta Arenas'],
        ]));
    }

    public function test_incluye_region_configurada(): void
    {
        config(['cotiz.mercadopublico.regiones' => [13, 5]]);

        $this->assertFalse(CompraAgilRegionScope::debeExcluirItem([
            'institucion' => [
                'region' => 13,
                'organismo_comprador' => 'Hospital Santiago',
            ],
        ]));
    }

    public function test_sin_region_no_excluye(): void
    {
        config(['cotiz.mercadopublico.regiones' => [13]]);

        $this->assertFalse(CompraAgilRegionScope::debeExcluirItem([
            'institucion' => ['organismo_comprador' => 'Organismo sin región'],
        ]));
    }

    public function test_excluye_magallanes_aunque_este_en_config(): void
    {
        config(['cotiz.mercadopublico.regiones' => [13, 12, 5]]);

        $this->assertNotContains(12, CompraAgilRegionScope::regionesIncluidas());
        $this->assertTrue(CompraAgilRegionScope::debeExcluirItem([
            'institucion' => ['region' => 12, 'organismo_comprador' => 'Hospital Punta Arenas'],
        ]));
    }

    public function test_excluye_isla_de_pascua_por_comuna(): void
    {
        config(['cotiz.mercadopublico.regiones' => [5, 13]]);

        $this->assertTrue(CompraAgilRegionScope::debeExcluirItem([
            'institucion' => [
                'region' => 5,
                'comuna' => 'Isla de Pascua',
                'organismo_comprador' => 'Municipalidad Isla de Pascua',
            ],
        ]));

        $this->assertTrue(CompraAgilRegionScope::debeExcluirResumen([
            'region' => 5,
            'comuna' => 'Rapa Nui',
        ]));

        $this->assertFalse(CompraAgilRegionScope::debeExcluirItem([
            'institucion' => [
                'region' => 5,
                'comuna' => 'Valparaíso',
                'organismo_comprador' => 'Hospital Valparaíso',
            ],
        ]));
    }

    public function test_catalogo_regiones_solo_incluidas(): void
    {
        config(['cotiz.mercadopublico.regiones' => [13, 5, 12, 99]]);

        $catalogo = CompraAgilRegionScope::catalogoRegiones();

        $this->assertSame([
            5 => 'Valparaíso',
            13 => 'Metropolitana',
        ], $catalogo);
    }
}

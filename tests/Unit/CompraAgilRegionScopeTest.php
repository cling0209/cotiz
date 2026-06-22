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

    public function test_catalogo_regiones_solo_incluidas(): void
    {
        config(['cotiz.mercadopublico.regiones' => [13, 5, 99]]);

        $catalogo = CompraAgilRegionScope::catalogoRegiones();

        $this->assertSame([
            5 => 'Valparaíso',
            13 => 'Metropolitana',
        ], $catalogo);
    }
}

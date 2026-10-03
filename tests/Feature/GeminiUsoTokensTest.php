<?php

namespace Tests\Feature;

use App\Services\CotizarIaService;
use App\Services\GeminiClientService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiUsoTokensTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'cotiz.gemini.api_key' => 'gratis',
            'cotiz.gemini.api_key_pago' => 'pago',
            'cotiz.gemini.pago_max_mes' => 0,
            'cotiz.gemini.modelos_respaldo' => [],
            'cotiz.gemini.reintento_espera_ms' => 0,
            'cotiz.gemini.precios' => [
                'entrada_usd_1m' => 1.0,
                'salida_usd_1m' => 10.0,
                'busqueda_usd' => 0.05,
                'usd_clp' => 1000,
            ],
        ]);
        Cache::flush();
    }

    private function respuesta(int $entrada, int $salida, int $pensamiento): array
    {
        return [
            'candidates' => [['content' => ['parts' => [['text' => '{"ok":true}']]]]],
            'usageMetadata' => [
                'promptTokenCount' => $entrada,
                'candidatesTokenCount' => $salida,
                'thoughtsTokenCount' => $pensamiento,
            ],
        ];
    }

    public function test_suma_tokens_y_costo_solo_de_la_cuenta_pagada(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push($this->respuesta(100_000, 10_000, 5_000))
                ->push($this->respuesta(200_000, 20_000, 0)),
        ]);

        $gemini = app(GeminiClientService::class);
        $gemini->reiniciarUso();
        // Sin búsqueda va a la cuenta gratuita; con búsqueda, directo a la pagada.
        $gemini->generar([['text' => 'a']], ['etapa' => 'Equivalencias']);
        $gemini->generar([['text' => 'b']], ['etapa' => 'Web', 'google_search' => true]);

        $uso = $gemini->resumenUso();

        $this->assertSame(2, $uso['llamadas']);
        $this->assertSame(1, $uso['llamadas_pago']);
        $this->assertSame(335_000, $uso['total_tokens']);
        // Pagada: 0,2 USD entrada + 0,2 USD salida + 0,05 búsqueda = 0,45 USD → $450.
        $this->assertSame(450, $uso['costo_clp']);
        // Gratuita referencial: 0,1 + 0,15 = 0,25 USD → $250.
        $this->assertSame(700, $uso['costo_referencial_clp']);
        $this->assertSame(['Equivalencias', 'Web'], array_column($uso['etapas'], 'etapa'));
        $this->assertSame(0, $uso['etapas'][0]['costo_clp']);
        $this->assertSame(250, $uso['etapas'][0]['costo_referencial_clp']);
    }

    public function test_solo_admin_y_pame_ven_el_consumo(): void
    {
        $this->assertTrue(CotizarIaService::usuarioVeConsumo('admin'));
        $this->assertTrue(CotizarIaService::usuarioVeConsumo(' PAME '));
        $this->assertFalse(CotizarIaService::usuarioVeConsumo('ejecutivo'));
        $this->assertFalse(CotizarIaService::usuarioVeConsumo(''));
    }

    public function test_reiniciar_uso_limpia_el_acumulado(): void
    {
        Http::fake(['*' => Http::response($this->respuesta(10, 10, 0))]);

        $gemini = app(GeminiClientService::class);
        $gemini->generar([['text' => 'a']]);
        $gemini->reiniciarUso();

        $this->assertSame(0, $gemini->resumenUso()['llamadas']);
        $this->assertSame([], $gemini->resumenUso()['etapas']);
    }
}

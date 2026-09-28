<?php

namespace Tests\Feature;

use App\Services\GeminiClientService;
use Tests\TestCase;

class GeminiDecodificarJsonTest extends TestCase
{
    private function decodificar(string $texto): mixed
    {
        return app(GeminiClientService::class)->decodificarJson($texto);
    }

    public function test_json_directo(): void
    {
        $this->assertSame(['resultados' => []], $this->decodificar('{"resultados":[]}'));
    }

    public function test_texto_con_corchetes_antes_del_json(): void
    {
        $texto = "Busqué en [mercadolibre.cl] y [sodimac.cl]. Resultados:\n"
            .'{"resultados":[{"i":3,"opciones":[{"sitio":"sodimac","titulo":"Cartulina {azul}","precio_clp":990,"unidades_por_pack":1,"url":"https://www.sodimac.cl/x"}]}]}'
            ."\nEspero que sirva [fin].";

        $data = $this->decodificar($texto);

        $this->assertSame(3, $data['resultados'][0]['i']);
        $this->assertSame('Cartulina {azul}', $data['resultados'][0]['opciones'][0]['titulo']);
    }

    public function test_bloque_markdown_y_comas_finales(): void
    {
        $texto = "Aquí está:\n```json\n{\"resultados\":[{\"i\":1,\"opciones\":[],},],}\n```";

        $this->assertSame(['resultados' => [['i' => 1, 'opciones' => []]]], $this->decodificar($texto));
    }

    public function test_json_cortado_devuelve_null(): void
    {
        $this->assertNull($this->decodificar('{"resultados":[{"i":1,"opciones":[{"sitio":"sodimac"'));
        $this->assertNull($this->decodificar('No encontré resultados.'));
    }
}

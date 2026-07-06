<?php

namespace Tests\Feature;

use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\User;
use App\Services\CotizacionExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CotizacionExportTrimTest extends TestCase
{
    use RefreshDatabase;

    private User $ejecutivo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        $this->ejecutivo = User::factory()->create([
            'username' => 'ejec01',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);
    }

    public function test_exportaciones_usan_codigo_y_softland_con_trim(): void
    {
        $nota = $this->crearNota();

        Maeprod::query()->create([
            'prod_item' => 'CARPUSI013',
            'prod_nombre' => 'CARPETA VINIL JM OFICIO AZUL',
            'prod_valor' => 350,
            'prod_valor_costo' => 350,
            'prod_item_softland' => 'SL-CARPUSI013',
        ]);

        DB::table('notasdetalle')->insert([
            'nronota' => $nota->nronota,
            'prod_item' => 'CARPUSI013 ',
            'prod_valor' => 350,
            'cantidad' => 2,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 350,
        ]);

        $service = app(CotizacionExportService::class);

        $guia = $this->streamedContent($service->respuestaGuiaTxt($nota));
        $this->assertStringStartsWith('CARPUSI013;;;;;;;2', $guia);

        $softland = $this->streamedContent($service->respuestaSoftlandTxt($nota));
        $this->assertStringContainsString('"SL-CARPUSI013"', $softland);
        $this->assertStringContainsString('"CARPETA VINIL JM OFICIO AZUL"', $softland);

        $excel = $this->streamedContent($service->respuestaExcel($nota));
        $this->assertStringContainsString('CARPUSI013;SL-CARPUSI013;CARPETA VINIL JM OFICIO AZUL', $excel);

        $guiaIngreso = $this->streamedContent($service->respuestaGuiaIngresoCsv($nota));
        $this->assertStringContainsString('SL-CARPUSI013', $guiaIngreso);
    }

    private function crearNota(): Nota
    {
        return Nota::query()->create([
            'nronota' => 13325,
            'descripcion' => 'Cotización export',
            'fecha' => now()->toDateString(),
            'usuario' => $this->ejecutivo->username,
            'encargado' => 'COT-13325',
            'empresa' => 'Cliente test',
            'celular' => '',
            'contacto' => '',
            'contactocorreo' => '',
            'nota_softland' => 10000,
            'enviadoapi' => 0,
            'factor_precio_venta' => 1.22,
        ]);
    }

    private function streamedContent(\Symfony\Component\HttpFoundation\StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        return $content === false ? '' : $content;
    }
}

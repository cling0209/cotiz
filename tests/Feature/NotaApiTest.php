<?php

namespace Tests\Feature;

use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotaApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cotiz.api_nota.user' => 'api_nota_user',
            'cotiz.api_nota.password' => 'api_nota_secret',
            'cotiz.api_nota.url' => '',
            'cotiz.api_nota_envio.url' => '',
        ]);
    }

    public function test_nota_api_rejects_without_auth(): void
    {
        $response = $this->postJson('/api/v1/nota', ['accion' => 'graba_resumen']);

        $response->assertStatus(401);
        $response->assertJson(['resultado' => 'ERROR']);
    }

    public function test_graba_resumen_and_detalle_creates_nota(): void
    {
        User::factory()->create([
            'username' => 'ejecutivo1',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);

        $resumen = $this->withBasicAuth('api_nota_user', 'api_nota_secret')
            ->postJson('/api/v1/nota', [
                'accion' => 'graba_resumen',
                'usuario' => 'ejecutivo1',
                'descripcion' => 'Cotización remota',
                'encargado' => 'COT-REMOTE-001',
                'empresa' => 'Cliente API',
                'rutempresa' => '76356855',
                'diashabiles' => 2,
                'notaorigen' => 99,
                'sistema' => 'Reicol',
            ]);

        $resumen->assertOk();
        $resumen->assertJson(['resultado' => 'OK']);
        $nronota = (int) $resumen->json('nronota');
        $this->assertGreaterThan(0, $nronota);

        $detalle = $this->withBasicAuth('api_nota_user', 'api_nota_secret')
            ->postJson('/api/v1/nota', [
                'accion' => 'graba_detalle',
                'nronota' => $nronota,
                'prod_item' => 'PROD001',
                'prod_nombre' => 'Producto remoto',
                'prod_familia' => 'PAPEL',
                'prod_valor' => 1500,
                'prod_valor_costo' => 1200,
                'cantidad' => 3,
                'orden' => 1,
            ]);

        $detalle->assertOk();
        $detalle->assertJson(['resultado' => 'OK']);

        $this->assertDatabaseHas('notas', [
            'nronota' => $nronota,
            'encargado' => 'COT-REMOTE-001',
            'notaorigen' => 99,
            'usuario' => 'ejecutivo1',
        ]);

        $this->assertDatabaseHas('maeprod', ['prod_item' => 'PROD001']);
        $this->assertDatabaseHas('notasdetalle', [
            'nronota' => $nronota,
            'prod_item' => 'PROD001',
            'cantidad' => 3,
        ]);
    }

    public function test_consulta_finds_nota_by_encargado(): void
    {
        User::factory()->create(['username' => 'ejecutivo1', 'perfil' => User::PERFIL_EJECUTIVO]);

        Nota::query()->create([
            'nronota' => 10,
            'descripcion' => 'Test',
            'fecha' => now()->toDateString(),
            'usuario' => 'ejecutivo1',
            'encargado' => 'COT-CONSULTA',
            'empresa' => '',
            'celular' => '',
            'contacto' => '',
            'contactocorreo' => '',
            'nota_softland' => 10000,
            'diashabiles' => 2,
            'notaorigen' => 0,
            'sistema' => 'Cotiz',
            'enviadoapi' => 0,
        ]);

        $ok = $this->withBasicAuth('api_nota_user', 'api_nota_secret')
            ->postJson('/api/v1/nota-consulta', [
                'accion' => 'cotizacion',
                'encargado' => 'COT-CONSULTA',
            ]);

        $ok->assertOk();
        $ok->assertJson([
            'resultado' => 'OK',
            'nronota' => 10,
        ]);

        $fail = $this->withBasicAuth('api_nota_user', 'api_nota_secret')
            ->postJson('/api/v1/nota-consulta', [
                'accion' => 'cotizacion',
                'encargado' => 'NO-EXISTE',
            ]);

        $fail->assertStatus(400);
        $fail->assertJson(['resultado' => 'ERROR']);
    }

    public function test_nota_envio_relay_forwards_resumen_and_detalle(): void
    {
        config([
            'cotiz.api_nota.user' => 'api_nota_user',
            'cotiz.api_nota.password' => 'api_nota_secret',
            'cotiz.api_nota.url' => 'https://destino.test/api/v1/nota',
            'cotiz.api_nota_envio.url' => '',
            'products.image_base_url' => '',
        ]);

        Http::fake([
            'destino.test/*' => function ($request) {
                $data = $request->data();
                $nronota = ($data['accion'] ?? '') === 'graba_resumen' ? 77 : (int) ($data['nronota'] ?? 77);

                return Http::response(['resultado' => 'OK', 'nronota' => $nronota], 200);
            },
        ]);

        Maeprod::query()->create([
            'prod_item' => 'DEMO001',
            'prod_nombre' => 'Papel bond',
            'prod_familia' => 'PAPEL',
            'prod_valor' => 1000,
            'prod_valor_costo' => 800,
        ]);

        Nota::query()->create([
            'nronota' => 5,
            'descripcion' => 'Origen',
            'fecha' => now()->toDateString(),
            'usuario' => 'ejecutivo1',
            'encargado' => 'COT-ENVIO-001',
            'empresa' => 'Empresa origen',
            'celular' => '',
            'contacto' => '',
            'contactocorreo' => '',
            'nota_softland' => 10001,
            'diashabiles' => 2,
            'notaorigen' => 0,
            'sistema' => 'Cotiz',
            'enviadoapi' => 0,
        ]);

        \App\Models\NotaDetalle::query()->create([
            'nronota' => 5,
            'prod_item' => 'DEMO001',
            'prod_valor' => 1000,
            'cantidad' => 2,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 800,
        ]);

        User::factory()->create(['username' => 'ejecutivo1', 'perfil' => User::PERFIL_EJECUTIVO]);

        $response = $this->withBasicAuth('api_nota_user', 'api_nota_secret')
            ->postJson('/api/v1/nota-envio', [
                'nronota' => 5,
                'enviadoapi' => 1,
            ]);

        $response->assertOk();
        $response->assertJson(['resultado' => 'OK']);

        Http::assertSentCount(2);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === 'https://destino.test/api/v1/nota'
                && ($data['accion'] ?? '') === 'graba_resumen'
                && ($data['notaorigen'] ?? null) === 5
                && ($data['encargado'] ?? '') === 'COT-ENVIO-001';
        });

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === 'https://destino.test/api/v1/nota'
                && ($data['accion'] ?? '') === 'graba_detalle'
                && ($data['nronota'] ?? null) === 77
                && ($data['prod_item'] ?? '') === 'DEMO001';
        });
    }
}

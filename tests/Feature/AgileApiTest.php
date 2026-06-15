<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_agile_api_rejects_without_auth(): void
    {
        $response = $this->postJson('/api/v1/agile', ['accion' => 'graba']);

        $response->assertStatus(401);
        $response->assertJson(['resultado' => 'ERROR']);
    }

    public function test_agile_api_graba_creates_pending_nota(): void
    {
        config([
            'cotiz.agile.user' => 'test_agile',
            'cotiz.agile.password' => 'secret',
            'cotiz.agile.sistema' => 'API',
            'cotiz.api_nota.consulta_nro_cotizacion' => '',
        ]);

        User::factory()->create([
            'username' => 'ejecutivo1',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);

        $payload = [
            'accion' => 'graba',
            'usuario' => 'ejecutivo1',
            'codigo_cotizacion' => 'COT-AGILE-TEST-001',
            'rut_empresa' => '76356855',
            'nombre_empresa' => 'Cliente Test',
            'productos' => [
                ['id' => 'AG001', 'descripcion' => 'Producto agile', 'cantidad' => 2],
            ],
        ];

        $response = $this->withBasicAuth('test_agile', 'secret')
            ->postJson('/api/v1/agile', $payload);

        $response->assertOk();
        $response->assertJson(['resultado' => 'OK']);
        $nronota = (int) $response->json('nroenvio');
        $this->assertGreaterThan(0, $nronota);

        $this->assertDatabaseHas('notas', [
            'nronota' => $nronota,
            'encargado' => 'COT-AGILE-TEST-001',
            'sistema' => 'API',
            'estado' => 'Pendiente',
            'usuario' => 'ejecutivo1',
        ]);

        $this->assertDatabaseHas('notasdetalle', [
            'nronota' => $nronota,
            'prod_item_agile' => 'AG001',
            'cantidad' => 2,
        ]);

        $this->assertDatabaseHas('agilemaeprod', [
            'prod_item_agile' => 'AG001',
        ]);
    }

    public function test_agile_api_rejects_duplicate_encargado(): void
    {
        config([
            'cotiz.agile.user' => 'test_agile',
            'cotiz.agile.password' => 'secret',
            'cotiz.api_nota.consulta_nro_cotizacion' => '',
        ]);

        User::factory()->create(['username' => 'ejecutivo1', 'perfil' => User::PERFIL_EJECUTIVO]);

        $payload = [
            'accion' => 'graba',
            'usuario' => 'ejecutivo1',
            'codigo_cotizacion' => 'COT-DUP',
            'rut_empresa' => '1-9',
            'nombre_empresa' => 'Dup',
            'productos' => [['id' => 'A1', 'descripcion' => 'x', 'cantidad' => 1]],
        ];

        $this->withBasicAuth('test_agile', 'secret')->postJson('/api/v1/agile', $payload)->assertOk();

        $dup = $this->withBasicAuth('test_agile', 'secret')->postJson('/api/v1/agile', $payload);

        $dup->assertStatus(400);
        $dup->assertJson(['resultado' => 'ERROR']);
    }
}

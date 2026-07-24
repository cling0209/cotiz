<?php

namespace Tests\Feature;

use App\Models\Maeprod;
use App\Models\MaeprodFrase;
use App\Models\User;
use Database\Seeders\FamprodSeeder;
use Database\Seeders\GramajeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MaeprodFraseTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(GramajeSeeder::class);
        $this->seed(FamprodSeeder::class);

        $this->superadmin = User::factory()->create([
            'username' => 'superadmin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        Maeprod::query()->create([
            'prod_item' => 'DEMO003',
            'prod_nombre' => 'LAPIZ GRAFITO',
            'prod_valor' => 500,
            'prod_familia' => 'LIBR',
            'prod_gramaje' => 'unidad',
        ]);

        Maeprod::query()->create([
            'prod_item' => 'OTRO01',
            'prod_nombre' => 'OTRO PRODUCTO',
            'prod_valor' => 100,
            'prod_familia' => 'PAPEL',
            'prod_gramaje' => 'unidad',
        ]);
    }

    public function test_superadmin_puede_agregar_y_eliminar_frase(): void
    {
        $this->actingAs($this->superadmin)
            ->post(route('admin.productos.frases.store', 'DEMO003'), [
                'frase' => '  lapiz azul  ',
            ])
            ->assertRedirect(route('admin.productos.edit', 'DEMO003'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('maeprod_frases', [
            'prod_item' => 'DEMO003',
            'frase' => 'lapiz azul',
            'frase_norm' => 'LAPIZ AZUL',
        ]);

        $frase = MaeprodFrase::query()->firstOrFail();

        $this->actingAs($this->superadmin)
            ->post(route('admin.productos.frases.destroy', [
                'prod_item' => 'DEMO003',
                'frase' => $frase->id,
            ]))
            ->assertRedirect(route('admin.productos.edit', 'DEMO003'));

        $this->assertDatabaseMissing('maeprod_frases', ['id' => $frase->id]);
    }

    public function test_ajax_agregar_y_eliminar_frase(): void
    {
        $headers = [
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ];

        $create = $this->actingAs($this->superadmin)
            ->postJson(route('admin.productos.frases.store', 'DEMO003'), [
                'frase' => 'adhesivo barra',
            ], $headers)
            ->assertOk()
            ->assertJsonPath('frase.frase', 'adhesivo barra');

        $fraseId = (int) $create->json('frase.id');
        $this->assertGreaterThan(0, $fraseId);

        $this->actingAs($this->superadmin)
            ->postJson(route('admin.productos.frases.destroy', [
                'prod_item' => 'DEMO003',
                'frase' => $fraseId,
            ]), [], $headers)
            ->assertOk()
            ->assertJsonPath('id', $fraseId);

        $this->assertDatabaseMissing('maeprod_frases', ['id' => $fraseId]);
    }

    public function test_ajax_frase_duplicada_devuelve_error(): void
    {
        MaeprodFrase::query()->create([
            'prod_item' => 'DEMO003',
            'frase' => 'lapiz azul',
            'frase_norm' => 'LAPIZ AZUL',
        ]);

        $this->actingAs($this->superadmin)
            ->postJson(route('admin.productos.frases.store', 'OTRO01'), [
                'frase' => 'lapiz azul',
            ], [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('frase');
    }

    public function test_frase_no_puede_repetirse_en_otro_producto(): void
    {
        MaeprodFrase::query()->create([
            'prod_item' => 'DEMO003',
            'frase' => 'lapiz azul',
            'frase_norm' => 'LAPIZ AZUL',
        ]);

        $this->actingAs($this->superadmin)
            ->from(route('admin.productos.edit', 'OTRO01'))
            ->post(route('admin.productos.frases.store', 'OTRO01'), [
                'frase' => 'LAPIZ AZUL',
            ])
            ->assertRedirect(route('admin.productos.edit', 'OTRO01'))
            ->assertSessionHasErrors('frase');

        $this->assertSame(1, MaeprodFrase::query()->count());
    }

    public function test_formulario_edicion_muestra_frases(): void
    {
        MaeprodFrase::query()->create([
            'prod_item' => 'DEMO003',
            'frase' => 'lapiz azul',
            'frase_norm' => 'LAPIZ AZUL',
        ]);

        $this->actingAs($this->superadmin)
            ->get(route('admin.productos.edit', 'DEMO003'))
            ->assertOk()
            ->assertSee('Frases para vincular')
            ->assertSee('lapiz azul');
    }

    public function test_eliminar_frase_sin_producto_redirige_al_listado(): void
    {
        $this->actingAs($this->superadmin)
            ->post(route('admin.productos.frases.destroy', [
                'prod_item' => 'NOEXISTE',
                'frase' => 999,
            ]))
            ->assertRedirect(route('admin.productos.index'))
            ->assertSessionHas('info', 'El producto ya no existe o fue eliminado.');
    }

    public function test_eliminar_frase_inexistente_refresca_edicion(): void
    {
        $this->actingAs($this->superadmin)
            ->post(route('admin.productos.frases.destroy', [
                'prod_item' => 'DEMO003',
                'frase' => 99999,
            ]))
            ->assertRedirect(route('admin.productos.edit', 'DEMO003'))
            ->assertSessionHas('info', 'La frase ya estaba eliminada.');
    }

    public function test_editar_producto_inexistente_redirige_al_listado(): void
    {
        $this->actingAs($this->superadmin)
            ->get(route('admin.productos.edit', 'NOEXISTE'))
            ->assertRedirect(route('admin.productos.index'))
            ->assertSessionHas('info', 'El producto ya no existe o fue eliminado.');
    }

    public function test_agregar_frase_sincroniza_al_par(): void
    {
        config([
            'cotiz.sistema' => 'Romulo',
            'cotiz.api_usuario.url' => 'https://peer.test/api/v1/usuario',
            'cotiz.api_nota.user' => 'api_user',
            'cotiz.api_nota.password' => 'api_pass',
        ]);

        Http::fake([
            'https://peer.test/api/v1/maeprod-frase' => Http::response([
                'resultado' => 'OK',
                'created' => true,
            ], 200),
        ]);

        $this->actingAs($this->superadmin)
            ->post(route('admin.productos.frases.store', 'DEMO003'), [
                'frase' => 'toner brother',
            ])
            ->assertRedirect(route('admin.productos.edit', 'DEMO003'))
            ->assertSessionHas('success');

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/maeprod-frase')
                && ($request['accion'] ?? null) === 'graba'
                && ($request['prod_item'] ?? null) === 'DEMO003'
                && ($request['frase_norm'] ?? null) === 'TONER BROTHER'
                && ($request['replicacion'] ?? null) === true;
        });
    }

    public function test_eliminar_frase_sincroniza_al_par(): void
    {
        config([
            'cotiz.sistema' => 'Romulo',
            'cotiz.api_usuario.url' => 'https://peer.test/api/v1/usuario',
            'cotiz.api_nota.user' => 'api_user',
            'cotiz.api_nota.password' => 'api_pass',
        ]);

        Http::fake([
            'https://peer.test/api/v1/maeprod-frase' => Http::response([
                'resultado' => 'OK',
                'deleted' => true,
            ], 200),
        ]);

        $frase = MaeprodFrase::query()->create([
            'prod_item' => 'DEMO003',
            'frase' => 'toner brother',
            'frase_norm' => 'TONER BROTHER',
        ]);

        $this->actingAs($this->superadmin)
            ->post(route('admin.productos.frases.destroy', [
                'prod_item' => 'DEMO003',
                'frase' => $frase->id,
            ]))
            ->assertRedirect(route('admin.productos.edit', 'DEMO003'));

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/maeprod-frase')
                && ($request['accion'] ?? null) === 'elimina'
                && ($request['frase_norm'] ?? null) === 'TONER BROTHER';
        });
    }

    public function test_api_graba_y_elimina_frase_desde_par(): void
    {
        config([
            'cotiz.api_nota.user' => 'api_user',
            'cotiz.api_nota.password' => 'api_pass',
        ]);

        $this->withBasicAuth('api_user', 'api_pass')
            ->postJson('/api/v1/maeprod-frase', [
                'accion' => 'graba',
                'replicacion' => true,
                'prod_item' => 'DEMO003',
                'frase' => 'papel bond a4',
                'frase_norm' => 'PAPEL BOND A4',
            ])
            ->assertOk()
            ->assertJson([
                'resultado' => 'OK',
                'created' => true,
            ]);

        $this->assertDatabaseHas('maeprod_frases', [
            'prod_item' => 'DEMO003',
            'frase_norm' => 'PAPEL BOND A4',
        ]);

        $this->withBasicAuth('api_user', 'api_pass')
            ->postJson('/api/v1/maeprod-frase', [
                'accion' => 'elimina',
                'replicacion' => true,
                'prod_item' => 'DEMO003',
                'frase_norm' => 'PAPEL BOND A4',
            ])
            ->assertOk()
            ->assertJson([
                'resultado' => 'OK',
                'deleted' => true,
            ]);

        $this->assertDatabaseMissing('maeprod_frases', [
            'frase_norm' => 'PAPEL BOND A4',
        ]);
    }

    public function test_api_graba_omite_si_producto_no_existe(): void
    {
        config([
            'cotiz.api_nota.user' => 'api_user',
            'cotiz.api_nota.password' => 'api_pass',
        ]);

        $this->withBasicAuth('api_user', 'api_pass')
            ->postJson('/api/v1/maeprod-frase', [
                'accion' => 'graba',
                'prod_item' => 'NOEXISTE',
                'frase' => 'algo raro',
                'frase_norm' => 'ALGO RARO',
            ])
            ->assertOk()
            ->assertJson([
                'resultado' => 'OK',
                'skipped' => true,
            ]);

        $this->assertSame(0, MaeprodFrase::query()->where('frase_norm', 'ALGO RARO')->count());
    }
}

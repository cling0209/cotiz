<?php

namespace Tests\Feature;

use App\Enums\MaeprodSoftlandOrigen;
use App\Models\Maeprod;
use App\Models\MaeprodSoftlandAuditoria;
use App\Models\User;
use App\Services\NotaDetalleService;
use App\Services\NotaService;
use Database\Seeders\FamprodSeeder;
use Database\Seeders\GramajeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaeprodSoftlandAuditoriaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(GramajeSeeder::class);
        $this->seed(FamprodSeeder::class);

        $this->admin = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);
    }

    public function test_crear_producto_con_softland_registra_auditoria(): void
    {
        $this->actingAs($this->admin)->post(route('admin.productos.store'), [
            'prod_item' => 'AUD001',
            'prod_nombre' => 'Producto audit',
            'prod_familia' => 'PAPEL',
            'prod_gramaje' => 'unidad',
            'prod_valor' => 1000,
            'prod_valor_costo' => 800,
            'prod_item_softland' => 'SL-AUD-01',
        ])->assertRedirect();

        $this->assertDatabaseHas('maeprod', [
            'prod_item' => 'AUD001',
            'prod_item_softland' => 'SL-AUD-01',
        ]);

        $this->assertDatabaseHas('maeprod_softland_auditoria', [
            'prod_item' => 'AUD001',
            'usuario' => 'admin',
            'valor_anterior' => null,
            'valor_nuevo' => 'SL-AUD-01',
            'origen' => MaeprodSoftlandOrigen::PRODUCTO->value,
        ]);
    }

    public function test_actualizar_y_borrar_softland_registra_historial(): void
    {
        Maeprod::query()->create([
            'prod_item' => 'AUD002',
            'prod_nombre' => 'CON SOFTLAND',
            'prod_familia' => 'PAPEL',
            'prod_gramaje' => 'unidad',
            'prod_valor' => 1000,
            'prod_item_softland' => 'SL-OLD',
        ]);

        $this->actingAs($this->admin)->put(route('admin.productos.update', 'AUD002'), [
            'prod_nombre' => 'CON SOFTLAND',
            'prod_familia' => 'PAPEL',
            'prod_gramaje' => 'unidad',
            'prod_valor' => 1000,
            'prod_valor_costo' => 0,
            'prod_item_softland' => 'SL-NEW',
        ])->assertRedirect();

        $this->assertDatabaseHas('maeprod_softland_auditoria', [
            'prod_item' => 'AUD002',
            'valor_anterior' => 'SL-OLD',
            'valor_nuevo' => 'SL-NEW',
            'origen' => MaeprodSoftlandOrigen::PRODUCTO->value,
        ]);

        $this->actingAs($this->admin)->put(route('admin.productos.update', 'AUD002'), [
            'prod_nombre' => 'CON SOFTLAND',
            'prod_familia' => 'PAPEL',
            'prod_gramaje' => 'unidad',
            'prod_valor' => 1000,
            'prod_valor_costo' => 0,
            'prod_item_softland' => '',
        ])->assertRedirect();

        $this->assertDatabaseHas('maeprod', [
            'prod_item' => 'AUD002',
            'prod_item_softland' => null,
        ]);

        $borrado = MaeprodSoftlandAuditoria::query()
            ->where('prod_item', 'AUD002')
            ->whereNull('valor_nuevo')
            ->first();

        $this->assertNotNull($borrado);
        $this->assertSame('SL-NEW', $borrado->valor_anterior);
        $this->assertSame(MaeprodSoftlandOrigen::PRODUCTO, $borrado->origen);
    }

    public function test_form_edit_muestra_historial_softland(): void
    {
        Maeprod::query()->create([
            'prod_item' => 'AUD003',
            'prod_nombre' => 'HISTORIAL',
            'prod_familia' => 'PAPEL',
            'prod_gramaje' => 'unidad',
            'prod_valor' => 1000,
            'prod_item_softland' => 'SL-V1',
        ]);

        MaeprodSoftlandAuditoria::query()->create([
            'prod_item' => 'AUD003',
            'usuario' => 'admin',
            'fechahora' => now(),
            'valor_anterior' => null,
            'valor_nuevo' => 'SL-V1',
            'origen' => MaeprodSoftlandOrigen::PRODUCTO,
            'nronota' => null,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.productos.edit', 'AUD003'))
            ->assertOk()
            ->assertSee('act. Softland', false)
            ->assertSee('Historial Softland')
            ->assertSee('Mantenedor de productos')
            ->assertSee('SL-V1');
    }

    public function test_cambio_softland_desde_cotizacion_registra_origen_y_nronota(): void
    {
        Maeprod::query()->create([
            'prod_item' => 'AUD004',
            'prod_nombre' => 'DESDE COTIZ',
            'prod_familia' => 'PAPEL',
            'prod_gramaje' => 'unidad',
            'prod_valor' => 1000,
            'prod_item_softland' => null,
        ]);

        $nota = app(NotaService::class)->crear('admin', 'Audit softland');
        $nota->detalle()->create([
            'prod_item' => 'AUD004',
            'orden' => 1,
            'cantidad' => 1,
            'prod_valor' => 1000,
            'prod_valor_costo' => 0,
            'fechahora' => now(),
        ]);

        app(NotaDetalleService::class)->actualizarLinea(
            $nota,
            'AUD004',
            1,
            [
                'prod_valor' => 1000,
                'cantidad' => 1,
                'prod_valor_costo' => 0,
                'prod_item_softland' => 'SL-COT',
            ],
            'admin',
        );

        $this->assertSame('SL-COT', Maeprod::query()->find('AUD004')?->prod_item_softland);

        $this->assertDatabaseHas('maeprod_softland_auditoria', [
            'prod_item' => 'AUD004',
            'usuario' => 'admin',
            'valor_nuevo' => 'SL-COT',
            'origen' => MaeprodSoftlandOrigen::COTIZACION->value,
            'nronota' => (int) $nota->nronota,
        ]);
    }
}

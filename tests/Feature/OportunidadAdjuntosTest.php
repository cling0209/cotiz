<?php

namespace Tests\Feature;

use App\Models\OportunidadEncontrada;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OportunidadAdjuntosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cotiz.mercadopublico.ticket' => 'test-ticket',
            'cotiz.mercadopublico.base_url' => 'https://api2.mercadopublico.cl',
            'cotiz.mercadopublico.adjuntos_disk' => 'r2_adjuntos',
            'cotiz.mercadopublico.adjuntos_prefix' => '',
            'cotiz.mercadopublico.compra_agil_user_key' => 'test-user-key',
            'cotiz.libreoffice.enabled' => true,
            'cotiz.libreoffice.url' => 'http://libreoffice.test',
            'filesystems.disks.r2_adjuntos.bucket' => 'mp-adjuntos',
            'filesystems.disks.r2_adjuntos.key' => 'test-key',
            'filesystems.disks.r2_adjuntos.secret' => 'test-secret',
        ]);
        Storage::fake('r2_adjuntos');
    }

    public function test_ejecutivo_no_puede_buscar_adjuntos(): void
    {
        $user = User::factory()->create([
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);

        $this->actingAs($user)
            ->postJson(route('admin.oportunidades.para-cotizar.adjuntos.buscar'), [
                'codigo' => '1000-1-COT26',
            ])
            ->assertForbidden();
    }

    public function test_superadmin_busca_adjuntos_y_los_guarda_en_r2(): void
    {
        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        OportunidadEncontrada::query()->create([
            'codigo' => '1000-1-COT26',
            'nombre' => 'Papel bond',
            'organismo' => 'Hospital Demo',
            'region' => 13,
            'nombre_region' => 'Metropolitana',
            'monto_presupuesto_clp' => 500000,
            'moneda' => 'CLP',
            'fecha_publicacion' => now()->subDay(),
            'fecha_cierre' => now()->addDays(5),
            'palabras_coinciden' => ['papel'],
            'cantidad_productos' => 3,
            'fecha_busqueda' => now()->toDateString(),
            'indice_region_config' => 0,
        ]);

        $pdf = '%PDF-1.4 fake-pdf-content-xxxxxxxx';

        Http::fake([
            'servicios-compra-agil.mercadopublico.cl/v1/adjuntos-compra-agil/listar/*' => Http::response([
                'success' => 'OK',
                'payload' => [
                    'files' => [
                        ['id' => '5f47e991-c525-40a0-b36c-44d53e538ae5', 'nombreArchivo' => 'bases.pdf'],
                    ],
                ],
            ], 200),
            'servicios-compra-agil.mercadopublico.cl/v1/adjuntos-compra-agil/descargar/*' => Http::response(
                $pdf,
                200,
                ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="bases.pdf"'],
            ),
            'api2.mercadopublico.cl/*' => Http::response(['success' => 'OK', 'payload' => []], 200),
            'buscador.mercadopublico.cl/*' => Http::response('<html></html>', 200),
        ]);

        $this->actingAs($user)
            ->postJson(route('admin.oportunidades.para-cotizar.adjuntos.buscar'), [
                'codigo' => '1000-1-COT26',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('guardados', 1);

        Storage::disk('r2_adjuntos')->assertExists('1000-1-COT26/bases.pdf');
        Storage::disk('r2_adjuntos')->assertExists('1000-1-COT26/manifest.json');

        $this->actingAs($user)
            ->getJson(route('admin.oportunidades.para-cotizar.adjuntos.estado'))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('consultados.0', '1000-1-COT26');

        $this->actingAs($user)
            ->getJson(route('admin.oportunidades.para-cotizar.adjuntos.listar', ['codigo' => '1000-1-COT26']))
            ->assertOk()
            ->assertJsonPath('archivos.0.nombre', 'bases.pdf');

        $this->actingAs($user)
            ->get(route('admin.oportunidades.para-cotizar.adjuntos.ver', [
                'codigo' => '1000-1-COT26',
                'archivo' => 'bases.pdf',
                'descargar' => 1,
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_superadmin_marca_consulta_si_no_hay_adjuntos(): void
    {
        $user = User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);

        OportunidadEncontrada::query()->create([
            'codigo' => '1000-1-COT26',
            'nombre' => 'Papel bond',
            'organismo' => 'Hospital Demo',
            'region' => 13,
            'nombre_region' => 'Metropolitana',
            'monto_presupuesto_clp' => 500000,
            'moneda' => 'CLP',
            'fecha_publicacion' => now()->subDay(),
            'fecha_cierre' => now()->addDays(5),
            'palabras_coinciden' => ['papel'],
            'cantidad_productos' => 3,
            'fecha_busqueda' => now()->toDateString(),
            'indice_region_config' => 0,
        ]);

        Http::fake([
            'servicios-compra-agil.mercadopublico.cl/v1/adjuntos-compra-agil/listar/*' => Http::response([
                'success' => 'OK',
                'payload' => ['files' => []],
            ], 200),
            'api2.mercadopublico.cl/*' => Http::response(['success' => 'OK', 'payload' => []], 200),
            'buscador.mercadopublico.cl/*' => Http::response('<html></html>', 200),
        ]);

        $this->actingAs($user)
            ->postJson(route('admin.oportunidades.para-cotizar.adjuntos.buscar'), [
                'codigo' => '1000-1-COT26',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('sin_adjuntos', true)
            ->assertJsonPath('guardados', 0);

        Storage::disk('r2_adjuntos')->assertExists('1000-1-COT26/manifest.json');
    }

    public function test_preview_pdf_no_llama_a_libreoffice(): void
    {
        $user = $this->superadmin();
        Storage::disk('r2_adjuntos')->put('1000-1-COT26/bases.pdf', '%PDF-1.4 original-pdf-xxxxxxxx');
        Http::fake([
            'libreoffice.test/*' => Http::response('should-not-run', 500),
        ]);

        $this->actingAs($user)
            ->get(route('admin.oportunidades.para-cotizar.adjuntos.ver', [
                'codigo' => '1000-1-COT26',
                'archivo' => 'bases.pdf',
                'preview' => 1,
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertSee('%PDF-1.4 original', false);

        Http::assertNothingSent();
    }

    public function test_preview_doc_convierte_a_pdf_y_cachea_en_r2(): void
    {
        $user = $this->superadmin();
        Storage::disk('r2_adjuntos')->put('1000-1-COT26/TEXTILES.doc', 'fake-ole-doc-content');
        Http::fake([
            'libreoffice.test/*' => Http::response('%PDF-1.4 converted-doc-xxxxxxxx', 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $this->actingAs($user)
            ->get(route('admin.oportunidades.para-cotizar.adjuntos.ver', [
                'codigo' => '1000-1-COT26',
                'archivo' => 'TEXTILES.doc',
                'preview' => 1,
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertSee('%PDF-1.4 converted', false);

        Storage::disk('r2_adjuntos')->assertExists('1000-1-COT26/_preview/TEXTILES.doc.pdf');

        Http::fake([
            'libreoffice.test/*' => Http::response('should-not-run', 500),
        ]);

        $this->actingAs($user)
            ->get(route('admin.oportunidades.para-cotizar.adjuntos.ver', [
                'codigo' => '1000-1-COT26',
                'archivo' => 'TEXTILES.doc',
                'preview' => 1,
            ]))
            ->assertOk()
            ->assertSee('%PDF-1.4 converted', false);

        Http::assertNothingSent();
    }

    public function test_preview_doc_sin_conversion_devuelve_html_y_no_500(): void
    {
        $user = $this->superadmin();
        Storage::disk('r2_adjuntos')->put('1000-1-COT26/TEXTILES.doc', 'fake-ole-doc-content');
        Http::fake([
            'libreoffice.test/*' => Http::response('fail', 422),
        ]);

        $this->actingAs($user)
            ->get(route('admin.oportunidades.para-cotizar.adjuntos.ver', [
                'codigo' => '1000-1-COT26',
                'archivo' => 'TEXTILES.doc',
                'preview' => 1,
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'text/html; charset=UTF-8')
            ->assertSee('descargar', false);
    }

    public function test_listar_oculta_pdf_de_preview(): void
    {
        $user = $this->superadmin();
        Storage::disk('r2_adjuntos')->put('1000-1-COT26/bases.pdf', '%PDF-1.4 x');
        Storage::disk('r2_adjuntos')->put('1000-1-COT26/_preview/TEXTILES.doc.pdf', '%PDF-1.4 y');
        Storage::disk('r2_adjuntos')->put('1000-1-COT26/manifest.json', '{}');

        $this->actingAs($user)
            ->getJson(route('admin.oportunidades.para-cotizar.adjuntos.listar', ['codigo' => '1000-1-COT26']))
            ->assertOk()
            ->assertJsonCount(1, 'archivos')
            ->assertJsonPath('archivos.0.nombre', 'bases.pdf');
    }

    private function superadmin(): User
    {
        return User::factory()->create([
            'username' => 'admin',
            'perfil' => User::PERFIL_SUPERADMIN,
        ]);
    }
}

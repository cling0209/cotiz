<?php

namespace Tests\Feature;

use App\Enums\NotaAuditoriaAccion;
use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\NotaAuditoria;
use App\Models\User;
use App\Services\NotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CotizacionAuditoriaModificacionPdfTest extends TestCase
{
    use RefreshDatabase;

    private User $dueño;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        $this->dueño = User::factory()->create([
            'username' => 'dueno01',
            'perfil' => User::PERFIL_EJECUTIVO,
        ]);

        $this->editor = User::factory()->create([
            'username' => 'editor01',
            'perfil' => User::PERFIL_SUPERADMIN,
            'nombre' => 'Editor',
            'apellidop' => 'Test',
        ]);
    }

    public function test_alta_y_modificacion_quedan_en_notasauditoria_con_fechahora(): void
    {
        $nota = app(NotaService::class)->crear($this->dueño->username, 'Cotización audit');

        $alta = NotaAuditoria::query()
            ->where('nronota', $nota->nronota)
            ->where('accion', NotaAuditoriaAccion::AGREGAR)
            ->first();

        $this->assertNotNull($alta);
        $this->assertSame('dueno01', $alta->usuario);
        $this->assertNotNull($alta->fechahora);
        $this->assertSame('Alta de cotización', $alta->observacion);

        app(NotaService::class)->modificarCabecera($nota, [
            'descripcion' => 'Cotización editada',
            'empresa' => 'Cliente X',
            'encargado' => 'COT-AUDIT',
        ], $this->editor->username);

        $mod = NotaAuditoria::query()
            ->where('nronota', $nota->nronota)
            ->where('accion', NotaAuditoriaAccion::MODIFICAR)
            ->latest('id')
            ->first();

        $this->assertNotNull($mod);
        $this->assertSame('editor01', $mod->usuario);
        $this->assertNotNull($mod->fechahora);
        $this->assertSame('Modificación de cabecera', $mod->observacion);
        $this->assertSame('dueno01', $nota->fresh()->usuario);
    }

    public function test_generar_pdf_registra_auditoria_con_fechahora(): void
    {
        $nota = $this->crearNota();

        Maeprod::query()->create([
            'prod_item' => 'PROD001',
            'prod_nombre' => 'Producto test',
            'prod_valor' => 100,
            'prod_valor_costo' => 80,
        ]);

        $nota->detalle()->create([
            'prod_item' => 'PROD001',
            'prod_valor' => 100,
            'cantidad' => 1,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 80,
        ]);

        $this->actingAs($this->editor)
            ->get(route('admin.cotizaciones.export.pdf', $nota->nronota))
            ->assertOk();

        $pdf = NotaAuditoria::query()
            ->where('nronota', $nota->nronota)
            ->where('accion', NotaAuditoriaAccion::PDF)
            ->first();

        $this->assertNotNull($pdf);
        $this->assertSame('editor01', $pdf->usuario);
        $this->assertNotNull($pdf->fechahora);
        $this->assertSame('Generación de PDF', $pdf->observacion);
        $this->assertSame('dueno01', $nota->fresh()->usuario);
    }

    private function crearNota(): Nota
    {
        return Nota::query()->create([
            'nronota' => 90001,
            'descripcion' => 'Cotización audit',
            'fecha' => now()->toDateString(),
            'usuario' => $this->dueño->username,
            'encargado' => 'COT-AUDIT',
            'empresa' => 'Cliente',
            'celular' => '',
            'contacto' => '',
            'contactocorreo' => '',
            'diashabiles' => 5,
            'enviadoapi' => 0,
        ]);
    }
}

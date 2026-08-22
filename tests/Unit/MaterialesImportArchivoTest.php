<?php

namespace Tests\Unit;

use App\Support\MaterialesImportArchivo;
use Tests\TestCase;

class MaterialesImportArchivoTest extends TestCase
{
    public function test_mensaje_incluye_nombre_y_limite(): void
    {
        config(['cotiz.materiales_import.max_archivo_mb' => 50]);

        $this->assertSame(50, MaterialesImportArchivo::maxMb());
        $this->assertSame(50 * 1024, MaterialesImportArchivo::maxKb());
        $this->assertFalse(MaterialesImportArchivo::superaLimite(50 * 1024 * 1024));
        $this->assertTrue(MaterialesImportArchivo::superaLimite(50 * 1024 * 1024 + 1));
        $this->assertSame(
            'El archivo «pedido.docx» supera el límite de 50 MB.',
            MaterialesImportArchivo::mensajeSuperaLimite('pedido.docx'),
        );
        $this->assertSame(
            'El archivo supera el límite de 50 MB.',
            MaterialesImportArchivo::mensajeSuperaLimite(),
        );
    }
}

<?php

namespace Tests\Unit;

use App\Services\CompraAgilImportService;
use App\Services\ListadoMaterialesPdfParserService;
use App\Services\MaterialesPdfImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class MaterialesPdfImportServiceCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_mismo_lock_id_reutiliza_parseo_entre_lotes(): void
    {
        Cache::flush();

        $pdf = UploadedFile::fake()->createWithContent('especificaciones.pdf', '%PDF-identico%');
        $lockId = (string) Str::uuid();

        $documento = [
            'cabecera' => [
                'codigo_cotizacion' => '',
                'empresa' => '',
                'rutempresa' => '',
                'nombre' => '',
            ],
            'lineas' => [
                ['cantidad' => 8, 'descripcion' => 'LAPICES DE CERA JUMBO'],
                ['cantidad' => 1, 'descripcion' => 'RESMA OFICIO'],
            ],
        ];

        $parser = Mockery::mock(ListadoMaterialesPdfParserService::class);
        $parser->shouldReceive('parseDocumentoCompleto')->once()->andReturn($documento);

        $compra = Mockery::mock(CompraAgilImportService::class);
        $compra->shouldReceive('previewLoteDesdeDatos')
            ->twice()
            ->andReturnUsing(function (array $datos, int $desde, int $hasta): array {
                $lineas = array_slice($datos['lineas'], $desde, max(0, $hasta - $desde));
                $total = count($datos['lineas']);

                return [
                    'lineas' => $lineas,
                    'total' => $total,
                    'procesadas' => $hasta,
                    'completado' => $hasta >= $total,
                    'resumen' => [
                        'total' => count($lineas),
                        'vinculados' => 0,
                        'pendientes' => count($lineas),
                        'con_sugerencia' => 0,
                    ],
                ];
            });

        $service = new MaterialesPdfImportService($parser, $compra);

        $lote1 = $service->previewLote($pdf, 0, 1, $lockId);
        $lote2 = $service->previewLote($pdf, 1, 2, $lockId);

        $this->assertSame(2, $lote1['total']);
        $this->assertSame(2, $lote2['total']);
        $this->assertCount(1, $lote1['lineas']);
        $this->assertCount(1, $lote2['lineas']);
    }

    public function test_lock_id_distinto_fuerza_nuevo_parseo(): void
    {
        Cache::flush();

        $pdf = UploadedFile::fake()->createWithContent('especificaciones.pdf', '%PDF-identico%');

        $documento = [
            'cabecera' => [
                'codigo_cotizacion' => '',
                'empresa' => '',
                'rutempresa' => '',
                'nombre' => '',
            ],
            'lineas' => [
                ['cantidad' => 1, 'descripcion' => 'PRODUCTO A'],
            ],
        ];

        $parser = Mockery::mock(ListadoMaterialesPdfParserService::class);
        $parser->shouldReceive('parseDocumentoCompleto')->twice()->andReturn($documento);

        $compra = Mockery::mock(CompraAgilImportService::class);
        $compra->shouldReceive('previewLoteDesdeDatos')
            ->twice()
            ->andReturnUsing(function (array $datos, int $desde, int $hasta): array {
                $lineas = array_slice($datos['lineas'], $desde, max(0, $hasta - $desde));
                $total = count($datos['lineas']);

                return [
                    'lineas' => $lineas,
                    'total' => $total,
                    'procesadas' => min($hasta, $total),
                    'completado' => $hasta >= $total,
                    'resumen' => [
                        'total' => count($lineas),
                        'vinculados' => 0,
                        'pendientes' => count($lineas),
                        'con_sugerencia' => 0,
                    ],
                ];
            });

        $service = new MaterialesPdfImportService($parser, $compra);

        $service->previewLote($pdf, 0, 1, (string) Str::uuid());
        $service->previewLote($pdf, 0, 1, (string) Str::uuid());
    }

    public function test_cache_key_incluye_version_y_lock(): void
    {
        $service = new MaterialesPdfImportService(
            app(ListadoMaterialesPdfParserService::class),
            app(CompraAgilImportService::class),
        );

        $tmp = tempnam(sys_get_temp_dir(), 'cotiz-cache-');
        file_put_contents($tmp, 'contenido-test');

        try {
            $key = $service->cacheKeyPdfImport($tmp, 'lock-abc');
            $this->assertStringContainsString('v38', $key);
            $this->assertStringContainsString('lock-abc', $key);
        } finally {
            @unlink($tmp);
        }
    }
}

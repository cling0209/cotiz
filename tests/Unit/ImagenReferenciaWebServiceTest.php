<?php

namespace Tests\Unit;

use App\Services\ImagenReferenciaWebService;
use App\Services\ProductImageProcessor;
use App\Services\ProductImageStorageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImagenReferenciaWebServiceTest extends TestCase
{
    public function test_url_permitida_prisa_usa_host_de_config(): void
    {
        config(['cotiz.prisa.base_url' => 'https://prisa.test']);

        $this->assertTrue(ImagenReferenciaWebService::urlPermitidaPrisa('https://prisa.test/media/foto.jpg'));
        $this->assertFalse(ImagenReferenciaWebService::urlPermitidaPrisa('https://evil.example/foto.jpg'));
    }

    public function test_guardar_prisa_copia_al_bucket(): void
    {
        config([
            'cotiz.prisa.base_url' => 'https://prisa.test',
            'products.storage_disk' => 'r2',
            'products.r2_prefix' => 'productos',
            'filesystems.disks.r2.bucket' => 'bucket',
            'filesystems.disks.r2.key' => 'key',
            'filesystems.disks.r2.secret' => 'secret',
        ]);
        Storage::fake('r2');
        Http::fake(['https://prisa.test/media/x.jpg' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg'])]);

        $processor = $this->createMock(ProductImageProcessor::class);
        $processor->method('processBinary')->willReturn(['contents' => 'jpeg-bytes', 'mime' => 'image/jpeg']);
        $storage = app(ProductImageStorageService::class);
        $svc = new ImagenReferenciaWebService($storage, $processor);

        $relativa = $svc->guardarPrisa('https://prisa.test/media/x.jpg', 'PRISA999');

        $this->assertSame('PRISA/'.now()->format('Y/m').'/PRISA999.jpg', $relativa);
        Storage::disk('r2')->assertExists('productos/'.$relativa);
    }
}

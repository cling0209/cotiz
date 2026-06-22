<?php

namespace Tests\Unit;

use App\Services\ProductImageProcessor;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ProductImageProcessorTest extends TestCase
{
    public function test_redimensiona_imagen_grande_a_cuadrado_jpeg(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available.');
        }

        config([
            'products.image_listing_size' => 400,
            'products.image_jpeg_quality' => 85,
        ]);

        $source = imagecreatetruecolor(1200, 800);
        $red = imagecolorallocate($source, 220, 38, 38);
        imagefilledrectangle($source, 0, 0, 1200, 800, $red);

        ob_start();
        imagepng($source);
        $png = ob_get_clean();
        imagedestroy($source);

        $file = UploadedFile::fake()->createWithContent('producto.png', $png);

        $result = (new ProductImageProcessor)->processUploadedFile($file);

        $this->assertNotNull($result);
        $this->assertSame('image/jpeg', $result['mime']);
        $this->assertSame('jpg', $result['extension']);

        $processed = imagecreatefromstring($result['contents']);
        $this->assertNotFalse($processed);
        $this->assertSame(400, imagesx($processed));
        $this->assertSame(400, imagesy($processed));
        imagedestroy($processed);
    }

    public function test_no_ampliar_imagen_pequena(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available.');
        }

        config([
            'products.image_listing_size' => 400,
            'products.image_jpeg_quality' => 85,
        ]);

        $source = imagecreatetruecolor(120, 80);
        $blue = imagecolorallocate($source, 37, 99, 235);
        imagefilledrectangle($source, 0, 0, 120, 80, $blue);

        ob_start();
        imagejpeg($source);
        $jpeg = ob_get_clean();
        imagedestroy($source);

        $file = UploadedFile::fake()->createWithContent('mini.jpg', $jpeg);

        $result = (new ProductImageProcessor)->processUploadedFile($file);

        $this->assertNotNull($result);

        $processed = imagecreatefromstring($result['contents']);
        $this->assertNotFalse($processed);
        $this->assertSame(400, imagesx($processed));
        $this->assertSame(400, imagesy($processed));
        imagedestroy($processed);
    }
}

<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

class ProductImageProcessor
{
    public function isAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    /**
     * @return array{contents: string, mime: string, extension: string}|null
     */
    public function processUploadedFile(UploadedFile $file): ?array
    {
        return $this->processBinary($file->get(), (string) ($file->getMimeType() ?: 'image/jpeg'));
    }

    /**
     * @return array{contents: string, mime: string, extension: string}|null
     */
    private function processBinary(string $contents, string $mime): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }

        if (! str_starts_with(strtolower($mime), 'image/')) {
            return null;
        }

        $source = @imagecreatefromstring($contents);

        if ($source === false) {
            return null;
        }

        $canvasSize = max(64, (int) config('products.image_listing_size', 400));
        $quality = min(100, max(50, (int) config('products.image_jpeg_quality', 85)));

        $canvas = imagecreatetruecolor($canvasSize, $canvasSize);

        if ($canvas === false) {
            imagedestroy($source);

            return null;
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $canvasSize, $canvasSize, $white);

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            imagedestroy($source);
            imagedestroy($canvas);

            return null;
        }

        $scale = min($canvasSize / $sourceWidth, $canvasSize / $sourceHeight, 1.0);
        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));
        $offsetX = (int) round(($canvasSize - $targetWidth) / 2);
        $offsetY = (int) round(($canvasSize - $targetHeight) / 2);

        imagealphablending($canvas, true);
        imagecopyresampled(
            $canvas,
            $source,
            $offsetX,
            $offsetY,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight,
        );

        ob_start();
        $saved = imagejpeg($canvas, null, $quality);
        $output = ob_get_clean();

        imagedestroy($source);
        imagedestroy($canvas);

        if ($saved === false || $output === false || $output === '') {
            return null;
        }

        return [
            'contents' => $output,
            'mime' => 'image/jpeg',
            'extension' => 'jpg',
        ];
    }
}

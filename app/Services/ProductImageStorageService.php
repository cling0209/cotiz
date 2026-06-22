<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProductImageStorageService
{
    public function __construct(
        protected ProductImageProcessor $processor,
    ) {}

    public function disk(): string
    {
        return (string) config('products.storage_disk', 'r2');
    }

    public function isConfigured(): bool
    {
        $disk = $this->disk();

        return (bool) config("filesystems.disks.{$disk}.bucket")
            && (bool) config("filesystems.disks.{$disk}.key")
            && filled(config('products.image_base_url'));
    }

    public function objectKey(string $familia, string $filename): string
    {
        $prefix = trim((string) config('products.r2_prefix', 'productos'), '/');
        $folder = trim($familia) !== '' ? trim($familia) : 'OTRO';
        $file = ltrim(trim($filename), '/');

        return $prefix.'/'.$folder.'/'.$file;
    }

    public function upload(UploadedFile $file, string $familia, string $prodItem): string
    {
        $processed = $this->processor->processUploadedFile($file);

        if ($processed !== null) {
            $filename = $this->buildFilename($prodItem, $processed['extension']);
            $contents = $processed['contents'];
            $mime = $processed['mime'];
        } else {
            $filename = $this->buildFilename($prodItem, $file);
            $contents = $file->get();
            $mime = $file->getMimeType() ?: 'image/jpeg';
        }

        $key = $this->objectKey($familia, $filename);

        Storage::disk($this->disk())->put($key, $contents, [
            'visibility' => 'public',
            'CacheControl' => 'public, max-age=31536000, immutable',
            'ContentType' => $mime,
        ]);

        return $filename;
    }

    public function buildFilename(string $prodItem, UploadedFile|string $source): string
    {
        $safeItem = preg_replace('/[^a-zA-Z0-9._-]+/', '_', trim($prodItem)) ?: 'producto';

        if ($source instanceof UploadedFile) {
            $extension = strtolower($source->extension() ?: $source->guessExtension() ?: 'jpg');
        } else {
            $extension = strtolower($source);
        }

        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $extension = 'jpg';
        }

        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        return $safeItem.'.'.$extension;
    }

    public function uploadFromLocalFile(string $localPath, string $familia, string $filename): void
    {
        $key = $this->objectKey($familia, $filename);
        $mime = mime_content_type($localPath) ?: 'image/jpeg';

        Storage::disk($this->disk())->put($key, file_get_contents($localPath), [
            'visibility' => 'public',
            'CacheControl' => 'public, max-age=31536000, immutable',
            'ContentType' => $mime,
        ]);
    }

    public function exists(string $familia, string $filename): bool
    {
        return Storage::disk($this->disk())->exists($this->objectKey($familia, $filename));
    }

    public function canUpload(): bool
    {
        $disk = $this->disk();

        return (bool) config("filesystems.disks.{$disk}.bucket")
            && (bool) config("filesystems.disks.{$disk}.key")
            && (bool) config("filesystems.disks.{$disk}.secret");
    }

    public function publicUrl(?string $familia, ?string $filename): ?string
    {
        $base = rtrim((string) config('products.image_base_url'), '/');
        $folder = trim((string) $familia);
        $file = trim((string) $filename);

        if ($base === '' || $file === '') {
            return null;
        }

        if ($folder === '') {
            return null;
        }

        return $base.'/'.trim($folder, '/').'/'.ltrim($file, '/');
    }
}

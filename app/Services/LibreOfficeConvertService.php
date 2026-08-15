<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cliente del sidecar LibreOffice (Office → PDF para vista previa de adjuntos).
 */
class LibreOfficeConvertService
{
    public function estaConfigurado(): bool
    {
        if (! filter_var(config('cotiz.libreoffice.enabled', true), FILTER_VALIDATE_BOOL)) {
            return false;
        }

        return $this->baseUrl() !== '';
    }

    public function extensionConvertible(string $nombre): bool
    {
        $ext = strtolower((string) pathinfo($nombre, PATHINFO_EXTENSION));

        return in_array($ext, ['doc', 'docx', 'xls', 'xlsx', 'odt', 'ods'], true);
    }

    public function convertirAPdf(string $contenido, string $nombre): string
    {
        if (! $this->estaConfigurado()) {
            throw new RuntimeException('Conversión a PDF no configurada (COTIZ_LIBREOFFICE_URL).');
        }
        if (! $this->extensionConvertible($nombre)) {
            throw new RuntimeException('Este tipo de archivo no se convierte a PDF.');
        }
        if ($contenido === '') {
            throw new RuntimeException('El archivo está vacío.');
        }

        $timeout = max(30, min(180, (int) config('cotiz.libreoffice.timeout', 120)));

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(8)
                ->withBody($contenido, 'application/octet-stream')
                ->withHeaders(['X-Filename' => $this->nombreAsciiParaSidecar($nombre)])
                ->post($this->baseUrl().'/convert');
        } catch (\Throwable $e) {
            Log::warning('LibreOffice no disponible para preview', [
                'nombre' => $nombre,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('No se pudo convertir el documento para mostrarlo.');
        }

        if (! $response->successful() || ! str_starts_with($response->body(), '%PDF')) {
            throw new RuntimeException('No se pudo convertir el documento para mostrarlo.');
        }

        return $response->body();
    }

    private function nombreAsciiParaSidecar(string $nombre): string
    {
        $ext = strtolower((string) pathinfo($nombre, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';

        return 'archivo.'.$ext;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('cotiz.libreoffice.url', ''), '/');
    }
}

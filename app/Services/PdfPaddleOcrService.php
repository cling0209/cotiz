<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente del sidecar PaddleOCR (tablas producto/cantidad en PDF escaneados).
 */
class PdfPaddleOcrService
{
    public function estaDisponible(): bool
    {
        try {
            if (! filter_var($this->config('paddleocr.enabled', true), FILTER_VALIDATE_BOOL)) {
                return false;
            }

            $url = $this->baseUrl();
            if ($url === '') {
                return false;
            }

            $response = Http::timeout(5)->get($url.'/health');

            return $response->successful()
                && is_array($response->json())
                && ($response->json('status') === 'ok');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    public function extraerLineasTabla(string $pdfPath): array
    {
        if (! is_readable($pdfPath)) {
            throw new RuntimeException('No se pudo leer el PDF para PaddleOCR.');
        }

        $url = $this->baseUrl();
        if ($url === '') {
            throw new RuntimeException('PaddleOCR no configurado (COTIZ_PADDLEOCR_URL).');
        }

        $timeout = max(30, (int) $this->config('paddleocr.timeout', 300));

        $response = Http::timeout($timeout)
            ->attach(
                'pdf',
                (string) file_get_contents($pdfPath),
                basename($pdfPath) !== '' ? basename($pdfPath) : 'documento.pdf',
            )
            ->post($url.'/extract-tabla');

        if (! $response->successful()) {
            $detalle = trim((string) ($response->json('detail') ?? $response->body()));

            throw new RuntimeException(
                'PaddleOCR no pudo procesar el PDF'.($detalle !== '' ? ': '.$detalle : '.'),
            );
        }

        $lineas = $response->json('lineas');
        if (! is_array($lineas)) {
            throw new RuntimeException('PaddleOCR devolvió una respuesta inválida.');
        }

        $normalizadas = [];
        foreach ($lineas as $fila) {
            if (! is_array($fila)) {
                continue;
            }
            $descripcion = trim((string) ($fila['descripcion'] ?? ''));
            if ($descripcion === '' || mb_strlen($descripcion) < 3) {
                continue;
            }
            $normalizadas[] = [
                'cantidad' => max(1, (int) ($fila['cantidad'] ?? 1)),
                'descripcion' => $descripcion,
            ];
        }

        return $normalizadas;
    }

    private function baseUrl(): string
    {
        $url = trim((string) $this->config('paddleocr.url', ''));

        return $url !== '' ? rtrim($url, '/') : '';
    }

    private function config(string $key, mixed $default = null): mixed
    {
        try {
            return config('cotiz.'.$key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}

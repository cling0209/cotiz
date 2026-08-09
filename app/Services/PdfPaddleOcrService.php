<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Smalot\PdfParser\Parser;

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
     * @return array<int, array{cantidad: int, descripcion: string, pagina?: int}>
     */
    public function extraerLineasTabla(string $pdfPath, string $nombreArchivo = ''): array
    {
        if (! is_readable($pdfPath)) {
            throw new RuntimeException('No se pudo leer el PDF para PaddleOCR.');
        }

        $url = $this->baseUrl();
        if ($url === '') {
            throw new RuntimeException('PaddleOCR no configurado (COTIZ_PADDLEOCR_URL).');
        }

        $pdfBytes = (string) file_get_contents($pdfPath);
        $nombre = $this->nombreArchivoParaPaddle($pdfPath, $nombreArchivo);
        $timeout = max(30, (int) $this->config('paddleocr.timeout', 300));
        $maxPaginas = max(1, min(30, (int) $this->config('paddleocr.max_pages', 15)));
        $paginasDoc = min($maxPaginas, $this->contarPaginasPdf($pdfPath));
        $umbralPorPagina = max(2, (int) $this->config('paddleocr.per_page_min_pages', 6));

        if ($paginasDoc >= $umbralPorPagina || $this->esNombreEspecificacionesTecnicas($nombre)) {
            return $this->extraerLineasTablaPorPaginaDesdeBytes(
                $url,
                $pdfBytes,
                $nombre,
                $timeout,
                $paginasDoc,
            );
        }

        try {
            $lineas = $this->solicitarLineasPaddle($url, $pdfBytes, $nombre, $timeout);

            if ($lineas !== []) {
                return $lineas;
            }
        } catch (\Throwable) {
            // Fallback página a página (menor RAM en sidecar).
        }

        return $this->extraerLineasTablaPorPaginaDesdeBytes(
            $url,
            $pdfBytes,
            $nombre,
            $timeout,
            $paginasDoc,
        );
    }

    /**
     * Extrae celdas página a página (mismo flujo que scripts/procesar_cuadrar_por_hoja.php).
     *
     * @return array<int, array{cantidad: int, descripcion: string, pagina?: int}>
     */
    public function extraerLineasTablaPorPagina(string $pdfPath, string $nombreArchivo = ''): array
    {
        if (! is_readable($pdfPath)) {
            throw new RuntimeException('No se pudo leer el PDF para PaddleOCR.');
        }

        $url = $this->baseUrl();
        if ($url === '') {
            throw new RuntimeException('PaddleOCR no configurado (COTIZ_PADDLEOCR_URL).');
        }

        $pdfBytes = (string) file_get_contents($pdfPath);
        $nombre = $this->nombreArchivoParaPaddle($pdfPath, $nombreArchivo);
        $timeout = max(30, (int) $this->config('paddleocr.timeout', 300));
        $maxPaginas = max(1, min(30, (int) $this->config('paddleocr.max_pages', 15)));
        $paginasDoc = min($maxPaginas, $this->contarPaginasPdf($pdfPath));

        return $this->extraerLineasTablaPorPaginaDesdeBytes(
            $url,
            $pdfBytes,
            $nombre,
            $timeout,
            $paginasDoc,
        );
    }

    /**
     * @return array<int, array{cantidad: int, descripcion: string, pagina?: int}>
     */
    private function extraerLineasTablaPorPaginaDesdeBytes(
        string $url,
        string $pdfBytes,
        string $nombre,
        int $timeout,
        int $maxPaginas,
    ): array {
        $concurrency = max(1, min(8, (int) $this->config('paddleocr.parallel_pages', 4)));
        $todas = [];

        for ($batchStart = 1; $batchStart <= $maxPaginas; $batchStart += $concurrency) {
            $batchEnd = min($maxPaginas, $batchStart + $concurrency - 1);
            $paginasBatch = range($batchStart, $batchEnd);

            $responses = Http::pool(function (Pool $pool) use ($url, $pdfBytes, $nombre, $timeout, $paginasBatch) {
                foreach ($paginasBatch as $pagina) {
                    $pool->as("p{$pagina}")
                        ->timeout($timeout)
                        ->attach('pdf', $pdfBytes, $nombre)
                        ->post($url.'/extract-tabla', [
                            'first_page' => $pagina,
                            'last_page' => $pagina,
                        ]);
                }
            }, count($paginasBatch));

            $batchConDatos = false;

            foreach ($paginasBatch as $pagina) {
                $response = $responses["p{$pagina}"] ?? null;

                if ($response instanceof \Throwable) {
                    Log::warning('Import PDF: Paddle por página falló', [
                        'pagina' => $pagina,
                        'error' => $response->getMessage(),
                    ]);

                    continue;
                }

                if (! $response instanceof \Illuminate\Http\Client\Response || ! $response->successful()) {
                    if ($pagina === 1) {
                        break 2;
                    }

                    continue;
                }

                $lineas = $this->normalizarLineasPaddle(is_array($response->json('lineas')) ? $response->json('lineas') : []);
                if ($lineas === []) {
                    if ($pagina === 1) {
                        break 2;
                    }

                    continue;
                }

                $batchConDatos = true;
                foreach ($lineas as $fila) {
                    $fila['pagina'] = $pagina;
                    $todas[] = $fila;
                }
            }

            if (! $batchConDatos && $batchStart > 1) {
                break;
            }
        }

        if ($todas === []) {
            throw new RuntimeException('PaddleOCR no pudo procesar el PDF.');
        }

        return $todas;
    }

    /**
     * @return array<int, array{cantidad: int, descripcion: string, pagina?: int}>
     */
    private function solicitarLineasPaddle(
        string $url,
        string $pdfBytes,
        string $nombre,
        int $timeout,
        ?int $firstPage = null,
        ?int $lastPage = null,
    ): array {
        $request = Http::timeout($timeout)
            ->attach('pdf', $pdfBytes, $nombre);

        $payload = [];
        if ($firstPage !== null) {
            $payload['first_page'] = $firstPage;
        }
        if ($lastPage !== null) {
            $payload['last_page'] = $lastPage;
        }

        $response = $payload === []
            ? $request->post($url.'/extract-tabla')
            : $request->post($url.'/extract-tabla', $payload);

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

        return $this->normalizarLineasPaddle($lineas);
    }

    /**
     * @param  array<int, mixed>  $lineas
     * @return array<int, array{cantidad: int, descripcion: string, pagina?: int}>
     */
    private function normalizarLineasPaddle(array $lineas): array
    {
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
                ...(isset($fila['pagina']) ? ['pagina' => max(1, (int) $fila['pagina'])] : []),
            ];
        }

        return $normalizadas;
    }

    private function contarPaginasPdf(string $pdfPath): int
    {
        try {
            $parser = new Parser;
            $pdf = $parser->parseFile($pdfPath);

            return max(1, count($pdf->getPages()));
        } catch (\Throwable) {
            return max(1, (int) $this->config('paddleocr.max_pages', 15));
        }
    }

    private function esNombreEspecificacionesTecnicas(string $nombre): bool
    {
        return preg_match('/ESPECIFICACIONES\s+TECNICAS/u', $nombre) === 1
            || preg_match('/ESPECIFICACIONES\s+TÉCNICAS/u', $nombre) === 1;
    }

    private function nombreArchivoParaPaddle(string $pdfPath, string $nombreArchivo): string
    {
        $nombre = trim($nombreArchivo);
        if ($nombre !== '') {
            return $nombre;
        }

        $base = basename($pdfPath);

        return $base !== '' ? $base : 'documento.pdf';
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

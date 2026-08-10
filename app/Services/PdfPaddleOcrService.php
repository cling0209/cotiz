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
        $paginasDoc = $this->resolverPaginasDocumento($pdfPath, $nombreArchivo);
        $umbralPorPagina = max(2, (int) $this->config('paddleocr.per_page_min_pages', 6));

        if ($this->esNombreBasesLicitacion($nombre)) {
            $firstPage = max(1, $paginasDoc - 24);

            return $this->extraerLineasTablaPorPaginaDesdeBytes(
                $url,
                $pdfBytes,
                $nombre,
                $timeout,
                $paginasDoc,
                max(1, min(4, (int) $this->config('paddleocr.parallel_pages', 2))),
                $firstPage,
            );
        }

        if ($paginasDoc >= $umbralPorPagina || $this->esNombreEspecificacionesTecnicas($nombre)) {
            $concurrency = $this->esNombreEspecificacionesTecnicas($nombre) ? 1 : 0;

            return $this->extraerLineasTablaPorPaginaDesdeBytes(
                $url,
                $pdfBytes,
                $nombre,
                $timeout,
                $paginasDoc,
                $concurrency,
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
        $paginasDoc = $this->resolverPaginasDocumento($pdfPath, $nombreArchivo);

        return $this->extraerLineasTablaPorPaginaDesdeBytes(
            $url,
            $pdfBytes,
            $nombre,
            $timeout,
            $paginasDoc,
        );
    }

    /**
     * Grilla cruda de celdas por página (para mapeo de columnas definido por el usuario).
     *
     * @return array<int, array{pagina: int, filas: array<int, array<int, string>>}>
     */
    public function extraerGrillaTabla(string $pdfPath, string $nombreArchivo = ''): array
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
        $paginasDoc = $this->resolverPaginasDocumento($pdfPath, $nombreArchivo);
        $concurrency = max(1, min(8, (int) $this->config('paddleocr.parallel_pages', 2)));

        /** @var array<int, array{pagina: int, filas: array<int, array<int, string>>}> $porPagina */
        $porPagina = [];

        for ($batchStart = 1; $batchStart <= $paginasDoc; $batchStart += $concurrency) {
            $batchEnd = min($paginasDoc, $batchStart + $concurrency - 1);
            $paginasBatch = range($batchStart, $batchEnd);

            $responses = Http::pool(function (Pool $pool) use ($url, $pdfBytes, $nombre, $timeout, $paginasBatch) {
                foreach ($paginasBatch as $pagina) {
                    $pool->as("p{$pagina}")
                        ->timeout($timeout)
                        ->attach('pdf', $pdfBytes, $nombre)
                        ->post($url.'/extract-grilla', [
                            'first_page' => $pagina,
                            'last_page' => $pagina,
                        ]);
                }
            }, count($paginasBatch));

            foreach ($paginasBatch as $pagina) {
                $grilla = $this->grillaDesdeRespuestaPool($responses["p{$pagina}"] ?? null, $pagina);
                if ($grilla !== null) {
                    $porPagina[$pagina] = $grilla;
                }
            }
        }

        $faltantes = array_values(array_diff(range(1, $paginasDoc), array_keys($porPagina)));
        foreach ($faltantes as $pagina) {
            try {
                $grilla = $this->solicitarGrillaPaddle($url, $pdfBytes, $nombre, $timeout, $pagina, $pagina);
                if ($grilla !== null) {
                    $porPagina[$pagina] = $grilla;
                }
            } catch (\Throwable $e) {
                Log::warning('Import PDF: grilla Paddle reintento falló', [
                    'pagina' => $pagina,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $todas = [];
        for ($pagina = 1; $pagina <= $paginasDoc; $pagina++) {
            if (isset($porPagina[$pagina])) {
                $todas[] = $porPagina[$pagina];
            }
        }

        if ($todas === []) {
            throw new RuntimeException('PaddleOCR no detectó tablas en el PDF.');
        }

        Log::info('Import PDF: grilla Paddle completada', [
            'paginas_doc' => $paginasDoc,
            'paginas_con_datos' => count($porPagina),
            'filas_total' => array_sum(array_map(
                static fn (array $p): int => count($p['filas'] ?? []),
                $todas,
            )),
        ]);

        return $todas;
    }

    /**
     * @return array{pagina: int, filas: array<int, array<int, string>>}|null
     */
    private function grillaDesdeRespuestaPool(mixed $response, int $pagina): ?array
    {
        if ($response instanceof \Throwable) {
            Log::warning('Import PDF: grilla Paddle por página falló', [
                'pagina' => $pagina,
                'error' => $response->getMessage(),
            ]);

            return null;
        }

        if (! $response instanceof \Illuminate\Http\Client\Response || ! $response->successful()) {
            return null;
        }

        $paginas = $response->json('paginas');
        if (! is_array($paginas) || $paginas === []) {
            return null;
        }

        $filas = [];
        foreach ($paginas as $bloque) {
            if (! is_array($bloque)) {
                continue;
            }
            foreach ($bloque['filas'] ?? [] as $fila) {
                if (! is_array($fila)) {
                    continue;
                }
                $celdas = array_values(array_filter(
                    array_map(static fn ($c): string => trim((string) $c), $fila),
                    static fn (string $c): bool => $c !== '',
                ));
                if ($celdas !== []) {
                    $filas[] = $celdas;
                }
            }
        }

        if ($filas === []) {
            return null;
        }

        return ['pagina' => $pagina, 'filas' => $filas];
    }

    /**
     * @return array{pagina: int, filas: array<int, array<int, string>>}|null
     */
    private function solicitarGrillaPaddle(
        string $url,
        string $pdfBytes,
        string $nombre,
        int $timeout,
        int $firstPage,
        int $lastPage,
    ): ?array {
        $response = Http::timeout($timeout)
            ->attach('pdf', $pdfBytes, $nombre)
            ->post($url.'/extract-grilla', [
                'first_page' => $firstPage,
                'last_page' => $lastPage,
            ]);

        if (! $response->successful()) {
            $detalle = trim((string) ($response->json('detail') ?? $response->body()));
            throw new RuntimeException(
                'PaddleOCR no pudo extraer la grilla'.($detalle !== '' ? ': '.$detalle : '.'),
            );
        }

        return $this->grillaDesdeRespuestaPool($response, $firstPage);
    }

    /**
     * Procesa TODAS las páginas: lote paralelo + reintento secuencial de hojas faltantes.
     *
     * @return array<int, array{cantidad: int, descripcion: string, pagina?: int}>
     */
    private function extraerLineasTablaPorPaginaDesdeBytes(
        string $url,
        string $pdfBytes,
        string $nombre,
        int $timeout,
        int $maxPaginas,
        int $concurrency = 0,
        int $firstPage = 1,
    ): array {
        $firstPage = max(1, $firstPage);
        $maxPaginas = max($firstPage, min(50, $maxPaginas));
        if ($concurrency <= 0) {
            $concurrency = max(1, min(8, (int) $this->config('paddleocr.parallel_pages', 2)));
        }

        /** @var array<int, array<int, array{cantidad: int, descripcion: string, pagina?: int}>> $porPagina */
        $porPagina = [];

        for ($batchStart = $firstPage; $batchStart <= $maxPaginas; $batchStart += $concurrency) {
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

            foreach ($paginasBatch as $pagina) {
                $lineas = $this->lineasDesdeRespuestaPool($responses["p{$pagina}"] ?? null, $pagina);
                if ($lineas !== []) {
                    $porPagina[$pagina] = $lineas;
                }
            }
        }

        $faltantes = array_values(array_diff(range($firstPage, $maxPaginas), array_keys($porPagina)));
        foreach ($faltantes as $pagina) {
            for ($intento = 1; $intento <= 3; $intento++) {
                try {
                    $lineas = $this->solicitarLineasPaddle($url, $pdfBytes, $nombre, $timeout, $pagina, $pagina);
                    if ($lineas === []) {
                        continue;
                    }
                    foreach ($lineas as $i => $fila) {
                        $lineas[$i]['pagina'] = $pagina;
                    }
                    $porPagina[$pagina] = $lineas;
                    break;
                } catch (\Throwable $e) {
                    if ($intento >= 3) {
                        Log::warning('Import PDF: Paddle reintento secuencial falló', [
                            'pagina' => $pagina,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        $todas = [];
        for ($pagina = $firstPage; $pagina <= $maxPaginas; $pagina++) {
            foreach ($porPagina[$pagina] ?? [] as $fila) {
                $todas[] = $fila;
            }
        }

        if ($todas === []) {
            throw new RuntimeException('PaddleOCR no pudo procesar el PDF.');
        }

        Log::info('Import PDF: Paddle por página completado', [
            'paginas_doc' => $maxPaginas,
            'paginas_con_datos' => count($porPagina),
            'filas_total' => count($todas),
            'concurrency' => $concurrency,
        ]);

        $promedioFilas = count($todas) / max(1, count($porPagina));
        if (
            $concurrency > 1
            && $this->esNombreEspecificacionesTecnicas($nombre)
            && ($promedioFilas < 6 || count($porPagina) < $maxPaginas)
        ) {
            Log::info('Import PDF: Paddle paralelo incompleto; reintento secuencial por hoja', [
                'filas_total' => count($todas),
                'paginas_con_datos' => count($porPagina),
            ]);

            return $this->extraerLineasTablaPorPaginaDesdeBytes(
                $url,
                $pdfBytes,
                $nombre,
                $timeout,
                $maxPaginas,
                1,
            );
        }

        return $todas;
    }

    /**
     * @return array<int, array{cantidad: int, descripcion: string, pagina?: int}>
     */
    private function lineasDesdeRespuestaPool(mixed $response, int $pagina): array
    {
        if ($response instanceof \Throwable) {
            Log::warning('Import PDF: Paddle por página falló', [
                'pagina' => $pagina,
                'error' => $response->getMessage(),
            ]);

            return [];
        }

        if (! $response instanceof \Illuminate\Http\Client\Response || ! $response->successful()) {
            return [];
        }

        $lineas = $this->normalizarLineasPaddle(is_array($response->json('lineas')) ? $response->json('lineas') : []);
        foreach ($lineas as $i => $fila) {
            $lineas[$i]['pagina'] = $pagina;
        }

        return $lineas;
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

    private function resolverPaginasDocumento(string $pdfPath, string $nombreArchivo): int
    {
        $maxConfig = max(1, min(30, (int) $this->config('paddleocr.max_pages', 30)));
        $nombre = $this->nombreArchivoParaPaddle($pdfPath, $nombreArchivo);

        if ($this->esNombreBasesLicitacion($nombre)) {
            $maxConfig = max($maxConfig, min(50, (int) $this->config('paddleocr.max_pages_bases', 50)));
        }

        if ($this->esNombreEspecificacionesTecnicas($nombre)) {
            try {
                $parser = new Parser;
                $pdf = $parser->parseFile($pdfPath);

                return min($maxConfig, max(1, count($pdf->getPages())));
            } catch (\Throwable) {
                return $maxConfig;
            }
        }

        try {
            $parser = new Parser;
            $pdf = $parser->parseFile($pdfPath);

            return min($maxConfig, max(1, count($pdf->getPages())));
        } catch (\Throwable) {
            return 1;
        }
    }

    private function esNombreBasesLicitacion(string $nombre): bool
    {
        $upper = mb_strtoupper($nombre);

        return str_contains($upper, 'BASES_LICT')
            || str_contains($upper, 'BASES LICT')
            || str_contains($upper, 'BASES ADMINISTRATIVAS')
            || (str_contains($upper, 'BASES') && str_contains($upper, 'MATERIAL'));
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

<?php

namespace App\Services;

use App\Models\Nota;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;

class MaterialesPdfImportService
{
    private const CACHE_VERSION = 'v42';

    public function __construct(
        protected ListadoMaterialesPdfParserService $parser,
        protected CompraAgilImportService $compraAgilImport,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(UploadedFile $file, ?string $lockId = null): array
    {
        $datos = $this->datosDesdePdf($file, $lockId);
        $total = count($datos['lineas']);
        $resultado = $this->compraAgilImport->previewLoteDesdeDatos($datos, 0, $total);
        unset($resultado['total'], $resultado['procesadas'], $resultado['completado']);

        return $resultado;
    }

    /**
     * @return array<string, mixed>
     */
    public function previewLote(UploadedFile $file, int $desde, int $hasta, ?string $lockId = null): array
    {
        $this->renovarLockImportacion($lockId);
        $datos = $this->datosDesdePdf($file, $lockId);
        $this->renovarLockImportacion($lockId);

        return $this->compraAgilImport->previewLoteDesdeDatos($datos, $desde, $hasta);
    }

    /**
     * @return array<string, mixed>
     */
    public function aplicar(Nota $nota, UploadedFile $file, string $usuario, ?string $lockId = null): array
    {
        $datos = $this->datosDesdePdf($file, $lockId);
        $total = count($datos['lineas']);
        $resultado = $this->compraAgilImport->aplicarLoteDesdeDatos($nota, $datos, $usuario, 0, $total);
        unset($resultado['total'], $resultado['procesadas'], $resultado['completado']);

        return $resultado;
    }

    /**
     * @return array<string, mixed>
     */
    public function aplicarLote(Nota $nota, UploadedFile $file, string $usuario, int $desde, int $hasta, ?string $lockId = null): array
    {
        $datos = $this->datosDesdePdf($file, $lockId);

        return $this->compraAgilImport->aplicarLoteDesdeDatos($nota, $datos, $usuario, $desde, $hasta);
    }

    /**
     * Importa desde el preview ya analizado (sin volver a leer el PDF/Word).
     *
     * @param  array{
     *   cabecera?: array{codigo_cotizacion?: string, empresa?: string, rutempresa?: string, nombre?: string},
     *   lineas: array<int, array<string, mixed>>
     * }  $datos
     * @return array<string, mixed>
     */
    public function aplicarLoteDesdePreview(Nota $nota, array $datos, string $usuario, int $desde, int $hasta): array
    {
        $normalizado = $this->normalizarDatosPreview($datos);

        return $this->compraAgilImport->aplicarLoteDesdeDatos($nota, $normalizado, $usuario, $desde, $hasta);
    }

    /**
     * @param  array{
     *   cabecera?: array{codigo_cotizacion?: string, empresa?: string, rutempresa?: string, nombre?: string},
     *   lineas: array<int, array<string, mixed>>
     * }  $datos
     * @return array{
     *   cabecera: array{codigo_cotizacion: string, empresa: string, rutempresa: string, nombre: string},
     *   lineas: array<int, array{id_agile: string, descripcion: string, cantidad: int, categoria: string, estado?: string, es_sugerencia?: bool, producto?: ?array<string, mixed>}>
     * }
     */
    private function normalizarDatosPreview(array $datos): array
    {
        $cabeceraIn = is_array($datos['cabecera'] ?? null) ? $datos['cabecera'] : [];
        $lineas = [];

        foreach ($datos['lineas'] ?? [] as $fila) {
            if (! is_array($fila)) {
                continue;
            }
            $descripcion = trim((string) ($fila['descripcion'] ?? ''));
            if ($descripcion === '') {
                continue;
            }
            $idAgile = trim((string) ($fila['id_agile'] ?? ''));
            if ($idAgile === '') {
                $idAgile = $this->idAgileParaDescripcion($descripcion);
            }

            $linea = [
                'id_agile' => mb_substr($idAgile, 0, 50),
                'descripcion' => mb_substr($descripcion, 0, 500),
                'cantidad' => max(1, (int) ($fila['cantidad'] ?? 1)),
                'categoria' => trim((string) ($fila['categoria'] ?? '')),
            ];

            if (isset($fila['estado'])) {
                $linea['estado'] = (string) $fila['estado'];
            }
            if (array_key_exists('es_sugerencia', $fila)) {
                $linea['es_sugerencia'] = (bool) $fila['es_sugerencia'];
            }
            if (isset($fila['producto']) && is_array($fila['producto'])) {
                $linea['producto'] = $fila['producto'];
            }

            $lineas[] = $linea;
        }

        return [
            'cabecera' => [
                'codigo_cotizacion' => trim((string) ($cabeceraIn['codigo_cotizacion'] ?? '')),
                'empresa' => trim((string) ($cabeceraIn['empresa'] ?? '')),
                'rutempresa' => trim((string) ($cabeceraIn['rutempresa'] ?? '')),
                'nombre' => trim((string) ($cabeceraIn['nombre'] ?? '')),
            ],
            'lineas' => $lineas,
        ];
    }

    /**
     * @return array{
     *   cabecera: array{codigo_cotizacion: string, empresa: string, rutempresa: string, nombre: string},
     *   lineas: array<int, array{id_agile: string, descripcion: string, cantidad: int, categoria: string}>
     * }
     */
    private function datosDesdePdf(UploadedFile $file, ?string $lockId = null): array
    {
        $path = $file->getRealPath() ?: $file->getPathname();
        $cacheKey = null;
        if (is_string($path) && is_readable($path)) {
            $cacheKey = $this->cacheKeyPdfImport($path, $lockId);
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && isset($cached['cabecera'], $cached['lineas'])) {
                return $cached;
            }
        }

        $this->renovarLockImportacion($lockId);
        $documento = $this->parser->parseDocumentoCompleto($file);
        $this->renovarLockImportacion($lockId);
        $lineas = [];

        foreach ($documento['lineas'] as $fila) {
            $descripcion = trim($fila['descripcion']);
            if ($descripcion === '') {
                continue;
            }

            $lineas[] = [
                'id_agile' => $this->idAgileParaDescripcion($descripcion),
                'descripcion' => $descripcion,
                'cantidad' => max(1, (int) $fila['cantidad']),
                'categoria' => '',
            ];
        }

        $datos = [
            'cabecera' => $documento['cabecera'],
            'lineas' => $lineas,
        ];

        if ($cacheKey !== null) {
            Cache::put($cacheKey, $datos, now()->addMinutes(45));
        }

        return $datos;
    }

    /**
     * Clave por lock_id + hash: cada "Analizar PDF" (nuevo lock) re-parsea;
     * los lotes del mismo análisis reutilizan el resultado sin repetir OCR/Paddle.
     */
    public function cacheKeyPdfImport(string $path, ?string $lockId = null): string
    {
        $hash = hash_file('sha1', $path) ?: 'unknown';
        $lockId = trim((string) $lockId);
        if ($lockId !== '') {
            return 'cotiz.pdf_import.'.self::CACHE_VERSION.'.'.$lockId.'.'.$hash;
        }

        return 'cotiz.pdf_import.'.self::CACHE_VERSION.'.'.$hash;
    }

    private function renovarLockImportacion(?string $lockId): void
    {
        $lockId = trim((string) $lockId);
        if ($lockId === '') {
            return;
        }

        app(MaterialesImportLockService::class)->touch($lockId);
    }

    private function idAgileParaDescripcion(string $descripcion): string
    {
        $normalizada = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $descripcion) ?? $descripcion));

        return 'pdf:'.substr(md5($normalizada), 0, 46);
    }
}

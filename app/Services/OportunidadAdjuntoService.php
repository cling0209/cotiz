<?php

namespace App\Services;

use App\Models\OportunidadEncontrada;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Html as HtmlWriter;
use RuntimeException;
use Throwable;
use ZipArchive;

class OportunidadAdjuntoService
{
    private ?string $compraAgilUserKeyMemo = null;

    public function __construct(
        protected CompraAgilApiService $compraAgilApi,
        protected LibreOfficeConvertService $libreOffice,
    ) {}

    public function disk(): string
    {
        return (string) config('cotiz.mercadopublico.adjuntos_disk', 'r2_adjuntos');
    }

    public function isConfigured(): bool
    {
        $disk = $this->disk();

        return (bool) config("filesystems.disks.{$disk}.bucket")
            && (bool) config("filesystems.disks.{$disk}.key")
            && (bool) config("filesystems.disks.{$disk}.secret");
    }

    public function prefix(): string
    {
        return trim((string) config('cotiz.mercadopublico.adjuntos_prefix', ''), '/');
    }

    public function carpeta(string $codigo): string
    {
        $codigo = $this->normalizarCodigo($codigo);
        $prefix = $this->prefix();

        return $prefix === '' ? $codigo : $prefix.'/'.$codigo;
    }

    public function normalizarCodigo(string $codigo): string
    {
        return strtoupper(trim($codigo));
    }

    /**
     * @return list<array{nombre: string, bytes: int, mime: string}>
     */
    public function listar(string $codigo): array
    {
        $this->assertConfigurado();
        $codigo = $this->normalizarCodigo($codigo);
        $carpeta = $this->carpeta($codigo);
        $disk = Storage::disk($this->disk());
        $out = [];

        foreach ($disk->files($carpeta) as $key) {
            $nombre = basename(str_replace('\\', '/', $key));
            if ($this->esArchivoInterno($key, $nombre)) {
                continue;
            }
            $out[] = [
                'nombre' => $nombre,
                'bytes' => (int) $disk->size($key),
                'mime' => $this->mimeDesdeNombre($nombre),
            ];
        }

        usort($out, fn ($a, $b) => strcasecmp($a['nombre'], $b['nombre']));

        return $out;
    }

    /**
     * @return array{archivos: array<string, list<string>>, consultados: list<string>}
     */
    public function indicePorCodigo(): array
    {
        $this->assertConfigurado();
        $prefix = $this->prefix();
        $porCodigo = [];
        $consultados = [];
        $disk = Storage::disk($this->disk());
        $root = $prefix === '' ? '' : $prefix;

        foreach ($disk->allFiles($root) as $key) {
            $rel = str_replace('\\', '/', $key);
            if ($prefix !== '') {
                $rel = ltrim(substr($rel, strlen($prefix)), '/');
            }
            $partes = explode('/', $rel, 2);
            if (count($partes) < 2) {
                continue;
            }
            $nombre = basename($partes[1]);
            if ($nombre === '') {
                continue;
            }
            $codigo = strtoupper($partes[0]);
            $consultados[$codigo] = true;
            if ($this->esArchivoInterno($key, $nombre) || str_starts_with($partes[1], '_preview/')) {
                continue;
            }
            $porCodigo[$codigo] ??= [];
            $porCodigo[$codigo][] = $nombre;
        }

        foreach ($porCodigo as &$nombres) {
            natcasesort($nombres);
            $nombres = array_values($nombres);
        }
        unset($nombres);

        $codigos = array_keys($consultados);
        sort($codigos);

        return [
            'archivos' => $porCodigo,
            'consultados' => $codigos,
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public function archivosPorCodigo(): array
    {
        return $this->indicePorCodigo()['archivos'];
    }

    /**
     * @return array<string, int>
     */
    public function conteosPorCodigo(): array
    {
        $conteos = [];
        foreach ($this->archivosPorCodigo() as $codigo => $nombres) {
            $conteos[$codigo] = count($nombres);
        }

        return $conteos;
    }

    public function contenido(string $codigo, string $nombre): string
    {
        $this->assertConfigurado();
        $key = $this->claveArchivo($codigo, $nombre);
        $disk = Storage::disk($this->disk());
        if (! $disk->exists($key)) {
            throw new RuntimeException('Archivo no encontrado.');
        }

        return (string) $disk->get($key);
    }

    public function claveArchivo(string $codigo, string $nombre): string
    {
        $safe = $this->nombreSeguro($nombre);
        if ($safe === '' || $safe === 'manifest.json') {
            throw new RuntimeException('Nombre de archivo inválido.');
        }

        return $this->carpeta($codigo).'/'.$safe;
    }

    public function yaConsultado(string $codigo): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }
        $codigo = $this->normalizarCodigo($codigo);
        if ($codigo === '') {
            return false;
        }
        try {
            return Storage::disk($this->disk())->exists($this->carpeta($codigo).'/manifest.json');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * En corrida: busca adjuntos si aún no se consultó. No lanza.
     */
    public function buscarSiPendiente(string $codigo): void
    {
        if (! $this->isConfigured()) {
            return;
        }
        $codigo = $this->normalizarCodigo($codigo);
        if ($codigo === '' || $this->yaConsultado($codigo)) {
            return;
        }
        try {
            $this->buscarYGuardar($codigo);
        } catch (Throwable $e) {
            Log::warning('OportunidadAdjunto: no se pudieron buscar adjuntos en corrida', [
                'codigo' => $codigo,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{codigo: string, guardados: int, omitidos: int, archivos: list<array{nombre: string, bytes: int, mime: string}>, consultado: true, sin_adjuntos: bool}
     */
    public function buscarYGuardar(string $codigo): array
    {
        $this->assertConfigurado();
        $codigo = $this->normalizarCodigo($codigo);
        if ($codigo === '') {
            throw new RuntimeException('Debe indicar el código de cotización.');
        }

        $existe = OportunidadEncontrada::query()
            ->whereRaw('UPPER(codigo) = ?', [$codigo])
            ->exists();
        if (! $existe) {
            throw new RuntimeException('La cotización no está clasificada como oportunidad.');
        }

        $candidatos = $this->recolectarCandidatos($codigo);
        $disk = Storage::disk($this->disk());
        $guardados = 0;
        $omitidos = 0;
        $usados = [];

        foreach ($candidatos as $candidato) {
            $nombre = $this->nombreUnico($this->nombreSeguro($candidato['nombre']), $usados);
            if ($nombre === '') {
                $omitidos++;

                continue;
            }
            $usados[$nombre] = true;
            $key = $this->carpeta($codigo).'/'.$nombre;
            $disk->put($key, $candidato['contents'], [
                'visibility' => 'private',
                'ContentType' => $candidato['mime'] ?: $this->mimeDesdeNombre($nombre),
            ]);
            $guardados++;
        }

        $archivos = $this->listar($codigo);
        $this->escribirManifest($codigo, array_column($archivos, 'nombre'));

        return [
            'codigo' => $codigo,
            'guardados' => $guardados,
            'omitidos' => $omitidos,
            'archivos' => $archivos,
            'consultado' => true,
            'sin_adjuntos' => $archivos === [],
        ];
    }

    /**
     * @param  list<string>  $nombres
     */
    private function escribirManifest(string $codigo, array $nombres): void
    {
        Storage::disk($this->disk())->put($this->carpeta($codigo).'/manifest.json', json_encode([
            'codigo' => $codigo,
            'actualizado_at' => now()->toIso8601String(),
            'archivos' => $nombres,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * PDF en caché (_preview/) o conversión LibreOffice. Si falla, Excel/DOCX HTML.
     *
     * @return array{body: string, mime: string, filename: string}
     */
    public function contenidoParaPreview(string $codigo, string $nombre, string $binario): array
    {
        $codigo = $this->normalizarCodigo($codigo);
        $safeName = $this->nombreSeguro($nombre);
        if ($this->esPdf($nombre)) {
            return [
                'body' => $binario,
                'mime' => 'application/pdf',
                'filename' => $safeName,
            ];
        }

        if ($this->necesitaConversionPreview($nombre)) {
            $pdf = $this->pdfPreviewDesdeCacheOConversion($codigo, $nombre, $binario);
            if ($pdf !== null) {
                return [
                    'body' => $pdf,
                    'mime' => 'application/pdf',
                    'filename' => pathinfo($safeName, PATHINFO_FILENAME).'.pdf',
                ];
            }
            if ($this->esExcel($nombre)) {
                try {
                    return [
                        'body' => $this->htmlPreviewExcel($binario),
                        'mime' => 'text/html; charset=UTF-8',
                        'filename' => $safeName,
                    ];
                } catch (\Throwable) {
                    throw new RuntimeException('No se pudo convertir el documento para mostrarlo. Puede descargarlo.');
                }
            }
            if (strtolower((string) pathinfo($nombre, PATHINFO_EXTENSION)) === 'docx') {
                try {
                    return [
                        'body' => $this->htmlPreviewDocx($binario),
                        'mime' => 'text/html; charset=UTF-8',
                        'filename' => $safeName,
                    ];
                } catch (\Throwable) {
                    throw new RuntimeException('No se pudo convertir el documento para mostrarlo. Puede descargarlo.');
                }
            }

            throw new RuntimeException('No se pudo convertir el documento para mostrarlo. Puede descargarlo.');
        }

        return [
            'body' => $binario,
            'mime' => $this->mimeDesdeNombre($nombre),
            'filename' => $safeName,
        ];
    }

    /**
     * Para analizar un .doc se usa el PDF convertido (caché o LibreOffice), no el Word original.
     *
     * @return array{body: string, mime: string, filename: string}
     */
    public function contenidoParaAnalisis(string $codigo, string $nombre, string $binario): array
    {
        $codigo = $this->normalizarCodigo($codigo);
        $safeName = $this->nombreSeguro($nombre);

        if ($this->esDocAntiguo($nombre)) {
            $pdf = $this->pdfPreviewDesdeCacheOConversion($codigo, $nombre, $binario);
            if ($pdf === null || ! str_starts_with($pdf, '%PDF')) {
                throw new RuntimeException(
                    'No se pudo convertir el .doc a PDF para analizarlo. Puede descargar el archivo original.',
                );
            }

            return [
                'body' => $pdf,
                'mime' => 'application/pdf',
                'filename' => pathinfo($safeName, PATHINFO_FILENAME).'.pdf',
            ];
        }

        return [
            'body' => $binario,
            'mime' => $this->mimeDesdeNombre($nombre),
            'filename' => $safeName,
        ];
    }

    public function necesitaConversionPreview(string $nombre): bool
    {
        return $this->esExcel($nombre) || $this->esWord($nombre);
    }

    public function clavePreviewPdf(string $codigo, string $nombre): string
    {
        return $this->carpeta($codigo).'/_preview/'.$this->nombreSeguro($nombre).'.pdf';
    }

    private function pdfPreviewDesdeCacheOConversion(string $codigo, string $nombre, string $binario): ?string
    {
        $key = $this->clavePreviewPdf($codigo, $nombre);
        $disk = Storage::disk($this->disk());
        if ($disk->exists($key)) {
            $cached = (string) $disk->get($key);
            if (str_starts_with($cached, '%PDF')) {
                return $cached;
            }
        }

        if (! $this->libreOffice->estaConfigurado()) {
            return null;
        }

        try {
            $pdf = $this->libreOffice->convertirAPdf($binario, $nombre);
            $disk->put($key, $pdf, [
                'visibility' => 'private',
                'ContentType' => 'application/pdf',
            ]);

            return $pdf;
        } catch (\Throwable) {
            return null;
        }
    }

    private function esArchivoInterno(string $key, string $nombre): bool
    {
        if ($nombre === '' || $nombre === 'manifest.json') {
            return true;
        }

        return str_contains(str_replace('\\', '/', $key), '/_preview/');
    }

    public function htmlPreviewExcel(string $contenido): string
    {
        $tmp = tmpfile();
        if ($tmp === false) {
            throw new RuntimeException('No se pudo crear archivo temporal.');
        }
        fwrite($tmp, $contenido);
        $meta = stream_get_meta_data($tmp);
        $path = $meta['uri'] ?? '';
        try {
            $spreadsheet = IOFactory::load($path);
            $writer = new HtmlWriter($spreadsheet);
            $writer->setSheetIndex(0);
            ob_start();
            $writer->save('php://output');
            $html = (string) ob_get_clean();
            $spreadsheet->disconnectWorksheets();

            return $html;
        } finally {
            fclose($tmp);
        }
    }

    public function htmlPreviewDocx(string $contenido): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        if ($tmp === false) {
            throw new RuntimeException('No se pudo crear archivo temporal.');
        }
        file_put_contents($tmp, $contenido);
        $zip = new ZipArchive;
        $ok = $zip->open($tmp);
        if ($ok !== true) {
            @unlink($tmp);
            throw new RuntimeException('No se pudo leer el Word.');
        }
        $xml = $zip->getFromName('word/document.xml') ?: '';
        $zip->close();
        @unlink($tmp);
        $texto = strip_tags(str_replace(['</w:p>', '</w:tr>'], "\n", $xml));
        $texto = html_entity_decode($texto, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $texto = preg_replace('/[ \t]+/u', ' ', $texto) ?? $texto;
        $texto = trim(preg_replace("/\n{3,}/", "\n\n", $texto) ?? $texto);

        return '<pre style="white-space:pre-wrap;font-family:inherit;">'
            .e(mb_substr($texto, 0, 20000))
            .'</pre>';
    }

    /**
     * @return list<array{nombre: string, contents: string, mime: string}>
     */
    private function recolectarCandidatos(string $codigo): array
    {
        $porNombre = [];

        foreach ($this->desdeServiciosCompraAgil($codigo) as $item) {
            $porNombre[$item['nombre']] = $item;
        }

        foreach ($this->desdeApiDetalle($codigo) as $item) {
            $porNombre[$item['nombre']] = $item;
        }

        foreach ($this->desdeFichaPublica($codigo) as $item) {
            $porNombre[$item['nombre']] = $item;
        }

        return array_values($porNombre);
    }

    /**
     * Adjuntos reales de Compra Ágil: UUID vía servicios-compra-agil (no el id numérico de api2).
     *
     * @return list<array{nombre: string, contents: string, mime: string}>
     */
    private function desdeServiciosCompraAgil(string $codigo): array
    {
        $userKey = $this->compraAgilUserKey();
        $base = rtrim((string) config(
            'cotiz.mercadopublico.compra_agil_adjuntos_base',
            'https://servicios-compra-agil.mercadopublico.cl',
        ), '/');

        $headers = ['Accept' => 'application/json'];
        if ($userKey !== '') {
            $headers['user_key'] = $userKey;
        }

        try {
            $response = $this->httpAdjuntosMp()
                ->withHeaders($headers)
                ->get($base.'/v1/adjuntos-compra-agil/listar/'.$codigo);
        } catch (\Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $files = data_get($response->json(), 'payload.files');
        if (! is_array($files)) {
            return [];
        }

        $out = [];
        foreach ($files as $file) {
            if (! is_array($file)) {
                continue;
            }
            $id = trim((string) ($file['id'] ?? ''));
            $nombre = trim((string) ($file['nombreArchivo'] ?? $file['nombre'] ?? ''));
            if ($id === '' || ! preg_match('/^[0-9a-f-]{36}$/i', $id)) {
                continue;
            }
            if ($nombre === '') {
                $nombre = $id.'.bin';
            }

            $extra = $userKey !== '' ? ['user_key' => $userKey] : [];
            $bajado = $this->bajarBinario(
                $base.'/v1/adjuntos-compra-agil/descargar/'.$id,
                $nombre,
                $extra,
            );
            if ($bajado !== null) {
                $out[] = $bajado;
            }
        }

        return $out;
    }

    private function compraAgilUserKey(): string
    {
        if ($this->compraAgilUserKeyMemo !== null) {
            return $this->compraAgilUserKeyMemo;
        }

        $configured = trim((string) config('cotiz.mercadopublico.compra_agil_user_key', ''));
        if ($configured !== '') {
            return $this->compraAgilUserKeyMemo = $configured;
        }

        try {
            $ua = ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'];
            $html = (string) $this->httpAdjuntosMp()->withHeaders($ua)
                ->get('https://buscador.mercadopublico.cl/')
                ->body();
            if (! preg_match('#/static/js/main\.[a-f0-9]+\.js#i', $html, $m)) {
                return $this->compraAgilUserKeyMemo = '';
            }
            $js = (string) $this->httpAdjuntosMp()->withHeaders($ua)
                ->get('https://buscador.mercadopublico.cl'.$m[0])
                ->body();
            if (! preg_match('/mn="([0-9a-fA-F-]{16,})"/', $js, $key)) {
                return $this->compraAgilUserKeyMemo = '';
            }

            return $this->compraAgilUserKeyMemo = $key[1];
        } catch (\Throwable) {
            return $this->compraAgilUserKeyMemo = '';
        }
    }

    private function httpAdjuntosMp(): \Illuminate\Http\Client\PendingRequest
    {
        $pending = Http::timeout(60)->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => '*/*',
        ]);

        if ((bool) config('cotiz.mercadopublico.http_without_verifying')) {
            $pending = $pending->withoutVerifying();
        }

        return $pending;
    }

    /**
     * @return list<array{nombre: string, contents: string, mime: string}>
     */
    private function desdeApiDetalle(string $codigo): array
    {
        try {
            $detalle = $this->compraAgilApi->detalle($codigo, false);
        } catch (RuntimeException) {
            return [];
        }

        $out = [];
        foreach ($this->extraerDocumentos($detalle) as $doc) {
            $bajado = $this->descargarDocumentoApi($codigo, $doc);
            if ($bajado !== null) {
                $out[] = $bajado;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $detalle
     * @return list<array{id: string, nombre: string, url: string}>
     */
    private function extraerDocumentos(array $detalle): array
    {
        $raw = $detalle['documentos'] ?? $detalle['adjuntos'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $doc) {
            if (! is_array($doc)) {
                continue;
            }
            $id = trim((string) ($doc['id'] ?? $doc['id_documento'] ?? ''));
            $nombre = trim((string) ($doc['nombre'] ?? $doc['nombre_archivo'] ?? $doc['name'] ?? ''));
            $url = trim((string) ($doc['url'] ?? $doc['uri'] ?? $doc['link'] ?? ''));
            if ($nombre === '') {
                $nombre = $id !== '' ? $id : 'adjunto.bin';
            }
            $out[] = [
                'id' => $id,
                'nombre' => $nombre,
                'url' => $url,
            ];
        }

        return $out;
    }

    /**
     * @param  array{id: string, nombre: string, url: string}  $doc
     * @return array{nombre: string, contents: string, mime: string}|null
     */
    private function descargarDocumentoApi(string $codigo, array $doc): ?array
    {
        $urls = [];
        if ($doc['url'] !== '') {
            $urls[] = $doc['url'];
        }
        if ($doc['id'] !== '') {
            $id = $doc['id'];
            $idEnc = rawurlencode($id);
            $esUuid = (bool) preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                $id,
            );
            if ($esUuid) {
                $urls[] = 'https://adjunto.mercadopublico.cl/adjunto-compra-agil/descargar/'.$idEnc;
            } else {
                $urls[] = 'https://www.mercadopublico.cl/FichaLicitacion/RetornaDocumento.aspx?id='.$idEnc;
            }
            $base = rtrim((string) config('cotiz.mercadopublico.base_url'), '/');
            $urls[] = $base.'/v2/compra-agil/'.rawurlencode($codigo).'/documentos/'.$idEnc;
            $urls[] = $base.'/v2/documentos/'.$idEnc;
        }

        foreach ($urls as $url) {
            $bajado = $this->bajarBinario($url, $doc['nombre']);
            if ($bajado !== null) {
                return $bajado;
            }
        }

        return null;
    }

    /**
     * @return list<array{nombre: string, contents: string, mime: string}>
     */
    private function desdeFichaPublica(string $codigo): array
    {
        $fichaUrl = 'https://buscador.mercadopublico.cl/ficha?code='.rawurlencode($codigo);
        try {
            $html = (string) Http::timeout(45)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 CotizAdjuntos'])
                ->get($fichaUrl)
                ->body();
        } catch (\Throwable) {
            return [];
        }

        if ($html === '') {
            return [];
        }

        $out = [];
        if (preg_match_all('/https?:\/\/adjunto\.mercadopublico\.cl\/adjunto-compra-agil\/descargar\/[0-9a-f-]+/i', $html, $cdn)) {
            foreach ($cdn[0] as $url) {
                $bajado = $this->bajarBinario($url, $this->nombreDesdeUrl($url));
                if ($bajado !== null) {
                    $out[$bajado['nombre']] = $bajado;
                }
            }
        }
        if (preg_match_all('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $html, $uuids)
            && str_contains(strtolower($html), 'adjunt')) {
            foreach (array_unique($uuids[0]) as $uuid) {
                $url = 'https://adjunto.mercadopublico.cl/adjunto-compra-agil/descargar/'.$uuid;
                $bajado = $this->bajarBinario($url, $uuid.'.pdf');
                if ($bajado !== null) {
                    $out[$bajado['nombre']] = $bajado;
                }
            }
        }
        if (preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $href) {
                $abs = $this->absoluta($fichaUrl, html_entity_decode($href, ENT_QUOTES, 'UTF-8'));
                if (! $this->pareceAdjunto($abs)) {
                    continue;
                }
                $nombre = $this->nombreDesdeUrl($abs);
                $bajado = $this->bajarBinario($abs, $nombre);
                if ($bajado !== null) {
                    $out[$bajado['nombre']] = $bajado;
                }
            }
        }

        return array_values($out);
    }

    /**
     * @return array{nombre: string, contents: string, mime: string}|null
     */
    /**
     * @param  array<string, string>  $extraHeaders
     * @return array{nombre: string, contents: string, mime: string}|null
     */
    private function bajarBinario(string $url, string $nombreSugerido, array $extraHeaders = []): ?array
    {
        $ticket = trim((string) config('cotiz.mercadopublico.ticket', ''));
        $esApiMp = str_contains(strtolower($url), 'api2.mercadopublico.cl');
        $headers = $extraHeaders;
        if ($esApiMp && $ticket !== '') {
            $headers['ticket'] = $ticket;
        }

        try {
            $pending = $this->httpAdjuntosMp()->withHeaders($headers);
            if ($esApiMp && $ticket !== '') {
                $pending = $pending->withQueryParameters(['ticket' => $ticket]);
            }
            $response = $pending->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $contents = $response->body();
        if ($contents === '' || strlen($contents) < 20) {
            return null;
        }

        $mime = strtolower((string) $response->header('Content-Type'));
        $esPdf = str_starts_with($contents, '%PDF');
        $esZip = str_starts_with($contents, 'PK');
        if (! $esPdf && ! $esZip && (str_contains($mime, 'text/html') || str_contains($mime, 'application/json'))) {
            return null;
        }
        if (! $esPdf && ! $esZip && str_starts_with(ltrim($contents), '<')) {
            return null;
        }

        $desdeHeader = $this->nombreDesdeContentDisposition((string) $response->header('Content-Disposition'));
        $nombre = $this->elegirNombreArchivo($nombreSugerido, $desdeHeader, $url);

        return [
            'nombre' => $nombre,
            'contents' => $contents,
            'mime' => $mime !== '' ? explode(';', $mime)[0] : $this->mimeDesdeNombre($nombre),
        ];
    }

    private function absoluta(string $base, string $href): string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, 'javascript:') || str_starts_with($href, '#')) {
            return '';
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            return 'https:'.$href;
        }
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        return $origin.'/'.ltrim($href, '/');
    }

    private function pareceAdjunto(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        $lower = strtolower($url);
        if (str_contains($lower, 'ficha?') || str_contains($lower, 'javascript')) {
            return false;
        }

        return (bool) preg_match('/\.(pdf|docx?|xlsx?|zip)(\?|$)/i', $url)
            || str_contains($lower, 'adjunto.mercadopublico.cl')
            || str_contains($lower, 'retornadocumento')
            || str_contains($lower, 'download')
            || str_contains($lower, 'adjunt')
            || str_contains($lower, 'attachment')
            || str_contains($lower, 'documento');
    }

    private function nombreDesdeUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $base = basename((string) $path);

        return $base !== '' ? rawurldecode($base) : 'adjunto.bin';
    }

    private function elegirNombreArchivo(string $sugerido, string $desdeHeader, string $url): string
    {
        if ($this->pareceNombreHumano($sugerido)) {
            return $sugerido;
        }
        if ($this->pareceNombreHumano($desdeHeader)) {
            return $desdeHeader;
        }

        return $sugerido !== '' ? $sugerido : ($desdeHeader !== '' ? $desdeHeader : $this->nombreDesdeUrl($url));
    }

    private function pareceNombreHumano(string $nombre): bool
    {
        $nombre = trim($nombre);
        if ($nombre === '') {
            return false;
        }
        $lower = strtolower($nombre);
        if (str_contains($lower, 'utf-8') || str_contains($lower, '=?')) {
            return false;
        }

        return (bool) preg_match('/\.(pdf|docx?|xlsx?|zip)$/i', $nombre);
    }

    private function nombreDesdeContentDisposition(string $header): string
    {
        if (preg_match("/filename\\*=(?:UTF-8)''([^;]+)/i", $header, $m)) {
            return rawurldecode(trim($m[1], " \t\"'"));
        }
        if (preg_match('/filename="([^"]+)"/i', $header, $m) || preg_match('/filename=([^;]+)/i', $header, $m)) {
            $raw = trim($m[1], " \t\"'");
            if (str_contains($raw, '=?')) {
                $decoded = function_exists('mb_decode_mimeheader') ? mb_decode_mimeheader($raw) : $raw;
                if (is_string($decoded) && $decoded !== '' && $decoded !== $raw) {
                    $raw = $decoded;
                } elseif (preg_match('/=\?utf-8\?B\?([^?]+)\?=/i', $raw, $b64)) {
                    $bin = base64_decode($b64[1], true);
                    if (is_string($bin) && $bin !== '') {
                        $raw = $bin;
                    }
                }
            }

            return $raw;
        }

        return '';
    }

    private function nombreSeguro(string $nombre): string
    {
        $base = basename(str_replace('\\', '/', $nombre));
        $base = preg_replace('/[^\p{L}\p{N}._ ()-]+/u', '_', $base) ?? '';
        $base = trim($base, '._ ');

        return mb_substr($base, 0, 180);
    }

    /**
     * @param  array<string, true>  $usados
     */
    private function nombreUnico(string $nombre, array $usados): string
    {
        if ($nombre === '' || ! isset($usados[$nombre])) {
            return $nombre;
        }
        $ext = pathinfo($nombre, PATHINFO_EXTENSION);
        $stem = pathinfo($nombre, PATHINFO_FILENAME);
        $i = 2;
        do {
            $candidato = $ext !== '' ? "{$stem}_{$i}.{$ext}" : "{$stem}_{$i}";
            $i++;
        } while (isset($usados[$candidato]));

        return $candidato;
    }

    public function mimeDesdeNombre(string $nombre): string
    {
        $ext = strtolower((string) pathinfo($nombre, PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc' => 'application/msword',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            'zip' => 'application/zip',
            default => 'application/octet-stream',
        };
    }

    public function esExcel(string $nombre): bool
    {
        return in_array(strtolower((string) pathinfo($nombre, PATHINFO_EXTENSION)), ['xlsx', 'xls'], true);
    }

    public function esWord(string $nombre): bool
    {
        return in_array(strtolower((string) pathinfo($nombre, PATHINFO_EXTENSION)), ['docx', 'doc'], true);
    }

    public function esDocAntiguo(string $nombre): bool
    {
        return strtolower((string) pathinfo($nombre, PATHINFO_EXTENSION)) === 'doc';
    }

    public function esPdf(string $nombre): bool
    {
        return strtolower((string) pathinfo($nombre, PATHINFO_EXTENSION)) === 'pdf';
    }

    private function assertConfigurado(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('R2 adjuntos no configurado: defina R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY y R2_ADJUNTOS_BUCKET.');
        }
    }
}

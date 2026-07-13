<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * OCR de PDFs escaneados vía pdftoppm + tesseract (Docker Alpine / Windows local).
 *
 * Optimizado para CPU limitada (Render free/starter): pocos DPI, JPEG gris,
 * un solo idioma y pocas páginas.
 */
class PdfOcrService
{
    private const MAX_PAGES = 3;

    /** Compromiso calidad/velocidad para CPU limitada (Render). */
    private const DPI = 150;

    private const TIMEOUT_PDFTOPPM = 90;

    private const TIMEOUT_TESSERACT = 180;

    public function estaDisponible(): bool
    {
        return $this->resolverBinario('pdftoppm') !== null
            && $this->resolverBinario('tesseract') !== null;
    }

    /**
     * @throws RuntimeException
     */
    public function extraerTexto(string $pdfPath): string
    {
        if (! is_readable($pdfPath)) {
            throw new RuntimeException('No se pudo leer el PDF para OCR.');
        }

        $pdftoppm = $this->resolverBinario('pdftoppm');
        $tesseract = $this->resolverBinario('tesseract');
        if ($pdftoppm === null || $tesseract === null) {
            throw new RuntimeException(
                'OCR no disponible en este servidor (faltan tesseract/pdftoppm). Use un PDF con texto nativo o Word (.docx).',
            );
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cotiz-ocr-'.bin2hex(random_bytes(8));
        if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('No se pudo crear directorio temporal para OCR.');
        }

        try {
            $prefijo = $dir.DIRECTORY_SEPARATOR.'page';
            $this->ejecutar([
                $pdftoppm,
                '-jpeg',
                '-gray',
                '-r',
                (string) self::DPI,
                '-f',
                '1',
                '-l',
                (string) self::MAX_PAGES,
                $pdfPath,
                $prefijo,
            ], self::TIMEOUT_PDFTOPPM);

            $imagenes = glob($prefijo.'-*.jpg') ?: [];
            if ($imagenes === []) {
                $imagenes = glob($prefijo.'-*.jpeg') ?: [];
            }
            sort($imagenes, SORT_NATURAL);
            if ($imagenes === []) {
                throw new RuntimeException('No se pudieron renderizar páginas del PDF para OCR.');
            }

            $idioma = $this->idiomaRapido($tesseract);
            $textos = [];
            foreach ($imagenes as $imagen) {
                $salida = $imagen.'.ocr';
                $this->ejecutar([
                    $tesseract,
                    $imagen,
                    $salida,
                    '-l',
                    $idioma,
                    '--oem',
                    '1',
                    '--psm',
                    '6',
                ], self::TIMEOUT_TESSERACT, [
                    'OMP_THREAD_LIMIT' => '1',
                ]);

                $archivo = $salida.'.txt';
                if (is_readable($archivo)) {
                    $fragmento = trim((string) file_get_contents($archivo));
                    if ($fragmento !== '') {
                        $textos[] = $fragmento;
                    }
                }
            }

            $texto = trim(implode("\n\n", $textos));
            if ($texto === '') {
                throw new RuntimeException('OCR no obtuvo texto legible del PDF escaneado.');
            }

            return $texto;
        } finally {
            $this->limpiarDirectorio($dir);
        }
    }

    /**
     * Preferir un solo idioma (spa) para no duplicar el costo de spa+eng en CPU débil.
     */
    private function idiomaRapido(string $tesseractBin): string
    {
        $disponibles = $this->idiomasInstalados($tesseractBin);
        if (in_array('spa', $disponibles, true)) {
            return 'spa';
        }
        if (in_array('eng', $disponibles, true)) {
            return 'eng';
        }

        return 'eng';
    }

    /**
     * @return list<string>
     */
    private function idiomasInstalados(string $tesseractBin): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        try {
            $salida = $this->ejecutar([$tesseractBin, '--list-langs'], 15);
            $cache = array_values(array_filter(
                array_map('trim', preg_split('/\r\n|\r|\n/', $salida) ?: []),
                static fn (string $linea) => $linea !== ''
                    && ! str_contains(mb_strtolower($linea), 'available')
                    && ! str_contains(mb_strtolower($linea), 'list of'),
            ));
        } catch (\Throwable) {
            $cache = ['eng'];
        }

        return $cache;
    }

    /**
     * @param  list<string>  $comando
     * @param  array<string, string>  $envExtra
     */
    private function ejecutar(array $comando, int $timeoutSegundos, array $envExtra = []): string
    {
        $process = new Process($comando);
        $process->setTimeout($timeoutSegundos);
        $env = array_merge($_ENV + $_SERVER, $envExtra);
        if (isset($comando[0]) && str_contains(str_replace('\\', '/', $comando[0]), 'Tesseract-OCR')) {
            $env['TESSDATA_PREFIX'] = 'C:\\Program Files\\Tesseract-OCR\\tessdata';
        }
        $process->setEnv($env);
        $process->run();

        if (! $process->isSuccessful()) {
            $err = trim($process->getErrorOutput() ?: $process->getOutput());

            throw new RuntimeException(
                'Fallo al ejecutar '.basename((string) ($comando[0] ?? 'comando')).($err !== '' ? ': '.$err : '.'),
            );
        }

        return $process->getOutput()."\n".$process->getErrorOutput();
    }

    private function resolverBinario(string $nombre): ?string
    {
        $candidatos = match ($nombre) {
            'tesseract' => [
                'tesseract',
                'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
            ],
            'pdftoppm' => array_values(array_filter([
                'pdftoppm',
                ...$this->buscarPdftoppmWindows(),
            ])),
            default => [$nombre],
        };

        foreach ($candidatos as $candidato) {
            if ($candidato === $nombre) {
                if ($this->comandoEnPath($nombre)) {
                    return $nombre;
                }

                continue;
            }

            if (is_file($candidato)) {
                return $candidato;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function buscarPdftoppmWindows(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [];
        }

        $encontrados = [];
        $base = getenv('LOCALAPPDATA') ?: '';
        if ($base !== '') {
            $glob = $base.'\\Microsoft\\WinGet\\Packages\\*Poppler*\\poppler-*\\Library\\bin\\pdftoppm.exe';
            foreach (glob($glob) ?: [] as $path) {
                $encontrados[] = $path;
            }
        }

        return $encontrados;
    }

    private function comandoEnPath(string $binario): bool
    {
        $process = Process::fromShellCommandline(
            PHP_OS_FAMILY === 'Windows' ? 'where '.$binario : 'command -v '.$binario
        );
        $process->setTimeout(10);
        $process->run();

        return $process->isSuccessful();
    }

    private function limpiarDirectorio(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*') ?: [] as $archivo) {
            @unlink($archivo);
        }
        @rmdir($dir);
    }
}

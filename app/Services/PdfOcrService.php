<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * OCR de PDFs escaneados vía pdftoppm + tesseract (Docker Alpine / Windows local).
 */
class PdfOcrService
{
    private const MAX_PAGES = 8;

    private const DPI = 200;

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

        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cotiz-ocr-'.bin2hex(random_bytes(8));
        if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('No se pudo crear directorio temporal para OCR.');
        }

        try {
            $prefijo = $dir.DIRECTORY_SEPARATOR.'page';
            $this->ejecutar([
                $pdftoppm,
                '-png',
                '-r',
                (string) self::DPI,
                '-f',
                '1',
                '-l',
                (string) self::MAX_PAGES,
                $pdfPath,
                $prefijo,
            ], 120);

            $imagenes = glob($prefijo.'-*.png') ?: [];
            sort($imagenes, SORT_NATURAL);
            if ($imagenes === []) {
                throw new RuntimeException('No se pudieron renderizar páginas del PDF para OCR.');
            }

            $textos = [];
            foreach ($imagenes as $imagen) {
                $salida = $imagen.'.ocr';
                $this->ejecutar([
                    $tesseract,
                    $imagen,
                    $salida,
                    '-l',
                    $this->idiomasTesseract($tesseract),
                    '--psm',
                    '6',
                ], 90);

                $archivo = $salida.'.txt';
                if (is_readable($archivo)) {
                    $textos[] = trim((string) file_get_contents($archivo));
                }
            }

            $texto = trim(implode("\n\n", array_filter($textos, static fn (string $t) => $t !== '')));
            if ($texto === '') {
                throw new RuntimeException('OCR no obtuvo texto legible del PDF escaneado.');
            }

            return $texto;
        } finally {
            $this->limpiarDirectorio($dir);
        }
    }

    private function idiomasTesseract(string $tesseractBin): string
    {
        $disponibles = $this->idiomasInstalados($tesseractBin);
        $elegidos = [];
        foreach (['spa', 'eng'] as $idioma) {
            if (in_array($idioma, $disponibles, true)) {
                $elegidos[] = $idioma;
            }
        }

        return $elegidos !== [] ? implode('+', $elegidos) : 'eng';
    }

    /**
     * @return list<string>
     */
    private function idiomasInstalados(string $tesseractBin): array
    {
        try {
            $salida = $this->ejecutar([$tesseractBin, '--list-langs'], 15);

            return array_values(array_filter(
                array_map('trim', preg_split('/\r\n|\r|\n/', $salida) ?: []),
                static fn (string $linea) => $linea !== ''
                    && ! str_contains(mb_strtolower($linea), 'available')
                    && ! str_contains(mb_strtolower($linea), 'list of'),
            ));
        } catch (\Throwable) {
            return ['eng'];
        }
    }

    /**
     * @param  list<string>  $comando
     */
    private function ejecutar(array $comando, int $timeoutSegundos): string
    {
        $process = new Process($comando);
        $process->setTimeout($timeoutSegundos);
        $env = $_ENV + $_SERVER;
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

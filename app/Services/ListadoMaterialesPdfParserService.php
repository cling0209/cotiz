<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use Smalot\PdfParser\Parser;

class ListadoMaterialesPdfParserService
{
    /**
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    public function parseUploadedFile(UploadedFile $file): array
    {
        $texto = $this->extraerTexto($file->getRealPath() ?: $file->getPathname());

        return $this->parseTexto($texto);
    }

    /**
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    public function parseTexto(string $texto): array
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $texto = preg_replace('/[ \t]+/u', ' ', $texto) ?? $texto;
        $lineas = array_values(array_filter(
            array_map(static fn (string $linea) => trim($linea), explode("\n", $texto)),
            static fn (string $linea) => $linea !== '',
        ));

        $resultado = [];
        $indiceActual = null;

        foreach ($lineas as $linea) {
            if ($this->esEncabezadoTabla($linea)) {
                continue;
            }

            if (preg_match('/^(\d{1,6})\s+(.+)$/u', $linea, $coincidencia) === 1) {
                $resultado[] = [
                    'cantidad' => max(1, (int) $coincidencia[1]),
                    'descripcion' => trim($coincidencia[2]),
                ];
                $indiceActual = count($resultado) - 1;

                continue;
            }

            if ($indiceActual !== null) {
                $resultado[$indiceActual]['descripcion'] = trim(
                    $resultado[$indiceActual]['descripcion'].' '.$linea,
                );
            }
        }

        return $resultado;
    }

    private function extraerTexto(string $path): string
    {
        if (! is_readable($path)) {
            throw new RuntimeException('No se pudo leer el archivo PDF.');
        }

        try {
            $parser = new Parser;
            $pdf = $parser->parseFile($path);
            $texto = trim((string) $pdf->getText());
        } catch (\Throwable $e) {
            throw new RuntimeException('No se pudo extraer texto del PDF. Verifique que no sea un documento escaneado.', 0, $e);
        }

        if ($texto === '') {
            throw new RuntimeException('El PDF no contiene texto legible. Use un listado con texto nativo, no escaneado.');
        }

        return $texto;
    }

    private function esEncabezadoTabla(string $linea): bool
    {
        $normalizada = mb_strtoupper($linea);

        return str_contains($normalizada, 'CANTIDAD')
            && (str_contains($normalizada, 'NOMBRE') || str_contains($normalizada, 'PRODUCTO'));
    }
}

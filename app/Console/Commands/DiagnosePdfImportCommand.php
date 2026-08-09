<?php

namespace App\Console\Commands;

use App\Services\ListadoMaterialesPdfParserService;
use Illuminate\Console\Command;

class DiagnosePdfImportCommand extends Command
{
    protected $signature = 'cotiz:diagnose-pdf
                            {path : Ruta al PDF (mismo flujo que Analizar PDF en la web)}
                            {--save : Guardar textos extraídos en storage/app/pdf-diag/}';

    protected $description = 'Diagnostica import PDF: nativo, Tesseract, Paddle y filas finales (VPS vs fixtures .txt)';

    public function handle(ListadoMaterialesPdfParserService $parser): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $this->error('No se encontró el archivo: '.$path);

            return self::FAILURE;
        }

        $this->info('Analizando: '.$path);
        $this->line('(Mismo pipeline que la web: extraerTextoPdf → parseTexto → Paddle)');

        try {
            $diag = $parser->diagnosticarPdf($path);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Herramienta', 'Disponible'],
            [
                ['Tesseract + pdftoppm', ($diag['herramientas']['tesseract_pdftoppm'] ?? false) ? 'sí' : 'no'],
                ['PaddleOCR sidecar', ($diag['herramientas']['paddleocr'] ?? false) ? 'sí' : 'no'],
            ],
        );

        $this->line('Formato: '.($diag['formato_detectado'] ?? '?')
            .' | Solicitud pedido: '.(($diag['es_solicitud_pedido'] ?? false) ? 'sí' : 'no')
            .' | Complementar OCR: '.(($diag['debe_complementar_ocr'] ?? false) ? 'sí' : 'no')
            .' | Mín. filas esperadas: '.($diag['min_lineas_esperadas'] ?? '?'));

        $conteos = $diag['conteos'] ?? [];
        $this->newLine();
        $this->table(
            ['Etapa', 'Filas'],
            [
                ['Texto nativo del PDF', $conteos['parse_nativo'] ?? 0],
                ['Tesseract (página completa)', $conteos['parse_ocr'] ?? 0],
                ['Tesseract (recorte columna)', $conteos['parse_ocr_crop'] ?? 0],
                ['Texto final elegido → parser', $conteos['parse_texto_final'] ?? 0],
                ['PaddleOCR sidecar', $conteos['paddle'] ?? 0],
                ['Import final (texto + Paddle)', $conteos['import_final'] ?? 0],
            ],
        );

        if (($diag['errores'] ?? []) !== []) {
            $this->warn('Errores parciales:');
            foreach ($diag['errores'] as $etapa => $msg) {
                $this->line("  {$etapa}: {$msg}");
            }
        }

        $this->newLine();
        $this->info('Filas finales del import:');
        foreach ($diag['lineas']['final'] ?? [] as $i => $fila) {
            $this->line(sprintf('  %d. [%s] %s', $i + 1, $fila['cantidad'], $fila['descripcion']));
        }

        if ($this->option('save')) {
            $dir = storage_path('app/pdf-diag/'.date('Ymd-His'));
            if (! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                $this->error('No se pudo crear '.$dir);

                return self::FAILURE;
            }
            foreach ($diag['texto'] ?? [] as $nombre => $contenido) {
                if (is_string($contenido) && $contenido !== '') {
                    file_put_contents($dir.'/'.$nombre.'.txt', $contenido);
                }
            }
            file_put_contents($dir.'/resumen.json', json_encode([
                'conteos' => $diag['conteos'],
                'lineas_final' => $diag['lineas']['final'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->newLine();
            $this->info('Textos guardados en: '.$dir);
            $this->line('Compara final.txt con tests/Fixtures/pdf_materiales/solicitud_pedido_pagina1.txt');
        }

        $final = (int) ($conteos['import_final'] ?? 0);
        $min = (int) ($diag['min_lineas_esperadas'] ?? 9);
        if ($final < $min && ($diag['es_solicitud_pedido'] ?? false)) {
            $this->newLine();
            $this->warn("Faltan filas ({$final}/{$min}). Revisa texto nativo/ocr con --save.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

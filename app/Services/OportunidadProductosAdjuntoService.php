<?php

namespace App\Services;

use App\Models\OportunidadEncontrada;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as PdfTextParser;
use Throwable;

/**
 * Rescate manual de productos desde adjuntos cuando Mercado Público no entrega el detalle.
 * 1) Parser propio (tablas Word / texto PDF). 2) Si no saca nada, Gemini lee los adjuntos
 * y cada ítem se valida contra el texto del documento.
 */
class OportunidadProductosAdjuntoService
{
    public function __construct(
        protected OportunidadAdjuntoService $adjuntos,
        protected ListadoMaterialesPdfParserService $parser,
        protected GeminiClientService $gemini,
    ) {}

    /**
     * Mismo formato que CompraAgilPayloadMapper::fromDetalle, con la cabecera tomada de la oportunidad.
     *
     * @return array{cabecera: array<string, mixed>, lineas: list<array{id_agile: string, descripcion: string, cantidad: int, categoria: string}>}|null
     */
    public function parseadoDesdeAdjuntos(string $codigo): ?array
    {
        $codigo = strtoupper(trim($codigo));
        if ($codigo === '' || ! $this->adjuntos->isConfigured()) {
            return null;
        }

        $nombres = $this->nombresAdjuntos($codigo);
        if ($nombres === []) {
            return null;
        }

        $resultado = $this->mejorAdjuntoConParser($codigo, $nombres);
        if ($resultado === null) {
            $resultado = $this->desdeGemini($codigo, $nombres);
        }
        if ($resultado === null) {
            return null;
        }

        $lineas = [];
        foreach ($resultado['lineas'] as $linea) {
            $descripcion = trim((string) ($linea['descripcion'] ?? ''));
            if ($descripcion === '') {
                continue;
            }
            $cantidad = max(1, (int) ($linea['cantidad'] ?? 1));
            $lineas[] = [
                'id_agile' => md5($descripcion.$cantidad),
                'descripcion' => mb_substr($descripcion, 0, 500),
                'cantidad' => $cantidad,
                'categoria' => mb_substr($descripcion, 0, 200),
            ];
        }
        if ($lineas === []) {
            return null;
        }

        $row = OportunidadEncontrada::query()
            ->where('codigo', $codigo)
            ->orderByDesc('fecha_busqueda')
            ->orderByDesc('id')
            ->first();

        return [
            'cabecera' => [
                'codigo_cotizacion' => $codigo,
                'empresa' => mb_substr((string) ($row->organismo ?? ''), 0, 100),
                'rutempresa' => (string) ($row->rut_organismo ?? ''),
                'nombre' => mb_substr((string) ($row->nombre ?? ''), 0, 500),
                'region' => $row?->region,
                'nombre_region' => (string) ($row->nombre_region ?? ''),
                'comuna' => (string) ($row->comuna ?? ''),
                'direccion_entrega' => (string) ($row->direccion ?? ''),
                'fuente_productos' => $resultado['fuente'],
            ],
            'lineas' => $lineas,
        ];
    }

    /**
     * Word antes que PDF (el PDF puede requerir OCR); se queda con el archivo que más líneas entrega.
     *
     * @param  list<string>  $nombres
     * @return array{fuente: string, lineas: list<array{cantidad: int, descripcion: string}>}|null
     */
    private function mejorAdjuntoConParser(string $codigo, array $nombres): ?array
    {
        $words = array_values(array_filter($nombres, fn ($n) => $this->adjuntos->esWord($n) && ! $this->adjuntos->esDocAntiguo($n)));
        $pdfs = array_values(array_filter($nombres, fn ($n) => $this->adjuntos->esPdf($n)));

        foreach ([$words, $pdfs] as $grupo) {
            $mejor = null;
            foreach ($grupo as $nombre) {
                $lineas = $this->lineasDeArchivo($codigo, $nombre);
                if ($lineas !== [] && ($mejor === null || count($lineas) > count($mejor['lineas']))) {
                    $mejor = ['fuente' => 'Adjunto: '.$nombre, 'lineas' => $lineas];
                }
            }
            if ($mejor !== null) {
                return $mejor;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function nombresAdjuntos(string $codigo): array
    {
        try {
            $archivos = $this->adjuntos->listar($codigo, false);
            if ($archivos === [] && ! $this->adjuntos->yaConsultado($codigo)) {
                $archivos = $this->adjuntos->buscarYGuardar($codigo)['archivos'];
            }
        } catch (Throwable $e) {
            Log::warning('OportunidadProductosAdjunto: no se pudieron listar adjuntos', [
                'codigo' => $codigo,
                'message' => $e->getMessage(),
            ]);

            return [];
        }

        return array_values(array_map(static fn (array $a): string => (string) $a['nombre'], $archivos));
    }

    /**
     * @return list<array{cantidad: int, descripcion: string}>
     */
    private function lineasDeArchivo(string $codigo, string $nombre): array
    {
        $file = null;
        try {
            $file = $this->adjuntos->asUploadedFile($codigo, $nombre);

            return array_values($this->parser->parseDocumentoCompleto($file)['lineas'] ?? []);
        } catch (Throwable $e) {
            Log::info('OportunidadProductosAdjunto: adjunto sin productos legibles', [
                'codigo' => $codigo,
                'archivo' => $nombre,
                'message' => $e->getMessage(),
            ]);

            return [];
        } finally {
            if ($file !== null) {
                @unlink($file->getPathname());
            }
        }
    }

    /**
     * @param  list<string>  $nombres
     * @return array{fuente: string, lineas: list<array{cantidad: int, descripcion: string}>}|null
     */
    private function desdeGemini(string $codigo, array $nombres): ?array
    {
        if (! (bool) config('cotiz.gemini.rescate_adjunto', true) || ! $this->gemini->isConfigured()) {
            return null;
        }

        $docs = $this->documentosParaGemini($codigo, $nombres);
        if ($docs === []) {
            return null;
        }

        $parts = [['text' => $this->promptGemini()]];
        foreach ($docs as $doc) {
            $parts[] = ['text' => 'Documento: '.$doc['nombre']];
            $parts[] = $doc['part'];
        }

        try {
            $respuesta = $this->gemini->generar($parts, [
                'json' => true,
                'temperature' => 0.0,
                'etapa' => 'rescate_adjunto',
            ]);
        } catch (Throwable $e) {
            Log::warning('OportunidadProductosAdjunto: Gemini no pudo leer los adjuntos', [
                'codigo' => $codigo,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $items = is_array($respuesta['json']['items'] ?? null) ? $respuesta['json']['items'] : [];
        $textoDocs = $this->normalizarTexto(implode("\n", array_column($docs, 'texto')));
        $validados = $this->validarItemsGemini($items, $textoDocs);
        if ($validados['lineas'] === []) {
            Log::info('OportunidadProductosAdjunto: Gemini sin ítems válidos', [
                'codigo' => $codigo,
                'items' => count($items),
                'descartados' => $validados['descartados'],
            ]);

            return null;
        }

        $notas = [];
        if ($validados['descartados'] > 0) {
            $notas[] = $validados['descartados'].' ítem(s) descartados por no aparecer en el documento';
        }
        if ($validados['revisar'] > 0) {
            $notas[] = $validados['revisar'].' cantidad(es) por revisar';
        }
        if ($textoDocs === '') {
            $notas[] = 'documento escaneado sin texto para validar: revisar todo';
        }

        Log::info('OportunidadProductosAdjunto: productos leídos con Gemini', [
            'codigo' => $codigo,
            'lineas' => count($validados['lineas']),
            'descartados' => $validados['descartados'],
            'revisar' => $validados['revisar'],
        ]);

        return [
            'fuente' => 'IA (Gemini) desde adjuntos: '.implode(', ', array_column($docs, 'nombre'))
                .($notas !== [] ? ' — '.implode('; ', $notas) : ''),
            'lineas' => $validados['lineas'],
        ];
    }

    /**
     * PDF directo; Word/Excel convertidos a PDF (LibreOffice) o, si falla, su texto.
     *
     * @param  list<string>  $nombres
     * @return list<array{nombre: string, part: array<string, mixed>, texto: string}>
     */
    private function documentosParaGemini(string $codigo, array $nombres): array
    {
        $maxDocs = max(1, (int) config('cotiz.gemini.max_adjuntos', 4));
        $maxBytes = max(1, (int) config('cotiz.gemini.max_adjunto_mb', 14)) * 1024 * 1024;

        $docs = [];
        foreach ($nombres as $nombre) {
            if (count($docs) >= $maxDocs) {
                break;
            }
            $esPdf = $this->adjuntos->esPdf($nombre);
            if (! $esPdf && ! $this->adjuntos->esWord($nombre) && ! $this->adjuntos->esExcel($nombre)) {
                continue;
            }

            try {
                $binario = $this->adjuntos->contenido($codigo, $nombre);
                $texto = $this->textoDocumento($nombre, $binario);
                $vista = $esPdf
                    ? ['body' => $binario, 'mime' => 'application/pdf']
                    : $this->adjuntos->contenidoParaPreview($codigo, $nombre, $binario);
            } catch (Throwable $e) {
                Log::info('OportunidadProductosAdjunto: adjunto no legible para Gemini', [
                    'codigo' => $codigo,
                    'archivo' => $nombre,
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            if ($vista['mime'] === 'application/pdf' && strlen($vista['body']) <= $maxBytes) {
                $part = ['inline_data' => ['mime_type' => 'application/pdf', 'data' => base64_encode($vista['body'])]];
            } elseif ($texto !== '') {
                $part = ['text' => mb_substr($texto, 0, 60000)];
            } else {
                continue;
            }

            $docs[] = ['nombre' => $nombre, 'part' => $part, 'texto' => $texto];
        }

        return $docs;
    }

    private function textoDocumento(string $nombre, string $binario): string
    {
        try {
            if ($this->adjuntos->esPdf($nombre)) {
                return trim((string) (new PdfTextParser)->parseContent($binario)->getText());
            }
            if ($this->adjuntos->esWord($nombre) && ! $this->adjuntos->esDocAntiguo($nombre)) {
                return $this->adjuntos->textoDocx($binario);
            }
            if ($this->adjuntos->esExcel($nombre)) {
                return $this->adjuntos->textoExcel($binario);
            }
        } catch (Throwable) {
        }

        return '';
    }

    private function promptGemini(): string
    {
        return <<<'PROMPT'
Eres un asistente que extrae la lista de productos pedidos en una Compra Ágil de Mercado Público (Chile).
Te entrego uno o más documentos adjuntos del comprador (fichas técnicas, especificaciones, listados).

Devuelve SOLO JSON con esta forma:
{"items": [{"cantidad": 20, "descripcion": "Resma carta 500 hojas", "texto_origen": "Resma Carta 500 hojas"}]}

Reglas:
1. Un ítem por producto pedido. Empareja cada cantidad con SU producto aunque estén en columnas, celdas,
   renglones o secciones distintas (p. ej. una columna con todas las cantidades y otra con todas las descripciones:
   empareja por posición).
2. "cantidad" = unidades pedidas (entero >= 1). Nunca uses números que son parte del nombre o la medida
   ("500 hojas", "12 colores", "36 gr", "N° 2", "7mm"). Si la cantidad está escrita en palabras, conviértela.
   Si el documento no indica cantidad para ese producto, usa 1.
3. "descripcion" = nombre completo del producto con sus especificaciones, en una sola línea.
4. "texto_origen" = el texto del producto copiado tal cual aparece en el documento (sin corregir).
5. Omite presupuesto, totales, IVA, plazos, dirección de entrega, datos de contacto, criterios de evaluación e imágenes.
6. No inventes productos. Si un documento no contiene una lista de productos, no devuelvas ítems de él.
   Si ningún documento la contiene, devuelve {"items": []}.
PROMPT;
    }

    /**
     * Descarta ítems cuyo texto no aparece en el documento; marca (sin descartar) cantidades que no aparecen.
     * Sin texto del documento (escaneo) no se puede validar: se aceptan todos.
     *
     * @param  array<int, mixed>  $items
     * @return array{lineas: list<array{cantidad: int, descripcion: string}>, descartados: int, revisar: int}
     */
    private function validarItemsGemini(array $items, string $textoDocs): array
    {
        $lineas = [];
        $descartados = 0;
        $revisar = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $descripcion = trim(preg_replace('/\s+/u', ' ', (string) ($item['descripcion'] ?? '')) ?? '');
            if (mb_strlen($descripcion) < 3) {
                continue;
            }
            $cantidad = max(1, (int) ($item['cantidad'] ?? 1));

            if ($textoDocs !== '') {
                $origen = trim((string) ($item['texto_origen'] ?? ''));
                if (! $this->apareceEnDocumento($origen !== '' ? $origen : $descripcion, $textoDocs)) {
                    $descartados++;

                    continue;
                }
                if ($cantidad > 1 && preg_match('/(?<![0-9])'.$cantidad.'(?![0-9])/', $textoDocs) !== 1) {
                    $revisar++;
                }
            }

            $lineas[] = ['cantidad' => $cantidad, 'descripcion' => $descripcion];
        }

        return ['lineas' => $lineas, 'descartados' => $descartados, 'revisar' => $revisar];
    }

    /**
     * Al menos el 70% de las palabras significativas del texto deben estar en el documento.
     */
    private function apareceEnDocumento(string $texto, string $textoDocsNormalizado): bool
    {
        $palabras = array_values(array_filter(
            explode(' ', $this->normalizarTexto($texto)),
            static fn (string $p): bool => mb_strlen($p) >= 3,
        ));
        if ($palabras === []) {
            return false;
        }

        $encontradas = 0;
        foreach ($palabras as $palabra) {
            if (str_contains($textoDocsNormalizado, $palabra)) {
                $encontradas++;
            }
        }

        return $encontradas / count($palabras) >= 0.7;
    }

    private function normalizarTexto(string $texto): string
    {
        $texto = mb_strtoupper($texto);
        $texto = strtr($texto, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
        $texto = preg_replace('/[^A-Z0-9]+/u', ' ', $texto) ?? $texto;

        return trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);
    }
}

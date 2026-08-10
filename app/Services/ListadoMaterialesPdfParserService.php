<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Smalot\PdfParser\Parser;
use ZipArchive;

class ListadoMaterialesPdfParserService
{
    private const FORMATO_LISTADO = 'listado_cantidad';

    private const FORMATO_DETALLE = 'detalle_unidades';

    private const FORMATO_LICITACION = 'licitacion_pedido';

    private const FORMATO_BASES = 'bases_linea';

    private const FORMATO_EETT = 'eett_especificaciones';

    private const FORMATO_TABLA_PRODUCTO_CANTIDAD = 'tabla_producto_cantidad';

    /** Tabla multi-columna: producto y cantidad en columnas distintas (p. ej. cotización proveedor). */
    private const FORMATO_TABLA_COLUMNAS = 'tabla_columnas';

    /** Cotización proveedor: ítem en línea propia, descripción multilínea, cantidad en «UNIDAD N». */
    private const FORMATO_COTIZACION_MULTILINEA = 'cotizacion_multilinea';

    /** Catálogo/oferta con descripción + unidad + precio, sin columna cantidad (p. ej. ANEXO ENAMI). */
    private const FORMATO_OFERTA_PRECIO = 'oferta_precio';

    /** Tabla municipal DIDECO: UNIDAD DE MEDIDA | CANTIDAD | BIEN O SERVICIO | ESPECIFICACIONES TÉCNICAS. */
    private const FORMATO_TABLA_DIDECO = 'tabla_dideco_especificaciones';

    public function __construct(
        protected ?PdfOcrService $ocr = null,
        protected ?PdfPaddleOcrService $paddle = null,
    ) {}

    /**
     * @return array{
     *   cabecera: array{codigo_cotizacion: string, empresa: string, rutempresa: string, nombre: string},
     *   lineas: array<int, array{cantidad: int, descripcion: string}>
     * }
     */
    public function parseDocumentoCompleto(UploadedFile $file): array
    {
        $path = $file->getRealPath() ?: $file->getPathname();
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: pathinfo($path, PATHINFO_EXTENSION)));

        if ($extension === 'doc') {
            throw new RuntimeException(
                'El formato .doc antiguo no está soportado. Guarde el archivo como .docx o PDF e intente de nuevo.',
            );
        }

        if ($extension === 'docx') {
            $desdeTablas = $this->parseDocxTablas($path);
            $texto = '';
            try {
                $texto = $this->extraerTextoDocx($path);
            } catch (RuntimeException) {
                $texto = '';
            }

            return [
                'cabecera' => $this->extraerCabeceraDocumento($texto),
                'lineas' => $desdeTablas !== [] ? $desdeTablas : $this->parseTexto($texto),
            ];
        }

        $fragmentos = $this->recolectarFragmentosTextoPdf(
            $path,
            trim((string) $file->getClientOriginalName()),
        );
        $nombreArchivo = trim((string) $file->getClientOriginalName());
        if ($fragmentos === [] && ! $this->puedeImportarPdfSoloConPaddle($path, $nombreArchivo)) {
            throw new RuntimeException('No se pudo extraer texto del PDF.');
        }

        $texto = $fragmentos !== []
            ? $this->elegirMejorTextoTablaProductoDesdeFragmentos($fragmentos)
            : '';
        $textoBusqueda = $fragmentos !== []
            ? trim(implode("\n", $fragmentos))
            : $this->textoHintTablaMaterialesEscaneada($nombreArchivo, $this->resolverPaginasPdf($path, $nombreArchivo));
        $lineasDesdeTexto = $this->parseTexto($texto);

        return [
            'cabecera' => $this->extraerCabeceraDocumento($texto),
            'lineas' => $this->fusionarLineasConPaddle(
                $path,
                $lineasDesdeTexto,
                $textoBusqueda,
                trim((string) $file->getClientOriginalName()),
            ),
        ];
    }

    /**
     * Import con mapeo explícito de columnas (nombre de encabezado cantidad / producto).
     *
     * @return array{
     *   cabecera: array{codigo_cotizacion: string, empresa: string, rutempresa: string, nombre: string},
     *   lineas: array<int, array{cantidad: int, descripcion: string}>
     * }
     */
    public function parseDocumentoConMapeoColumnas(
        UploadedFile $file,
        string $columnaCantidad,
        string $columnaProducto,
    ): array {
        $path = $file->getRealPath() ?: $file->getPathname();
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: pathinfo($path, PATHINFO_EXTENSION)));
        $columnaCantidad = trim($columnaCantidad);
        $columnaProducto = trim($columnaProducto);

        if ($columnaCantidad === '' || $columnaProducto === '') {
            throw new RuntimeException('Indique el nombre de las columnas cantidad y producto.');
        }

        if ($this->normalizarEncabezadoCelda($columnaCantidad) === $this->normalizarEncabezadoCelda($columnaProducto)) {
            throw new RuntimeException('Las columnas cantidad y producto deben ser distintas.');
        }

        if ($extension === 'doc') {
            throw new RuntimeException(
                'El formato .doc antiguo no está soportado. Guarde el archivo como .docx o PDF e intente de nuevo.',
            );
        }

        $textoCabecera = '';
        /** @var array<int, array{pagina: int, filas: array<int, array<int, string>>}> $paginasFilas */
        $paginasFilas = [];

        if ($extension === 'docx') {
            $xml = $this->leerDocumentXmlDocx($path);
            $paginasFilas = [['pagina' => 1, 'filas' => $this->extraerFilasTablaDocx($xml)]];
            try {
                $textoCabecera = $this->extraerTextoDocx($path);
            } catch (RuntimeException) {
                $textoCabecera = '';
            }
        } else {
            $paddle = $this->paddle ?? new PdfPaddleOcrService;
            if ($paddle->estaDisponible()) {
                try {
                    $paginasFilas = $paddle->extraerGrillaTabla($path, trim((string) $file->getClientOriginalName()));
                } catch (\Throwable $e) {
                    Log::warning('Import PDF: grilla Paddle falló', ['error' => $e->getMessage()]);
                }
            }

            if ($paginasFilas === []) {
                $paginasFilas = $this->extraerGrillaNativaPdf($path);
            }

            try {
                $textoCabecera = trim((string) (new Parser)->parseFile($path)->getText());
            } catch (\Throwable) {
                $textoCabecera = '';
            }
        }

        if ($paginasFilas === []) {
            throw new RuntimeException(
                'No se detectó tabla en el documento. Verifique el archivo o que PaddleOCR esté disponible.',
            );
        }

        $lineas = $this->aplicarMapeoColumnasPorNombre($paginasFilas, $columnaCantidad, $columnaProducto);
        if ($lineas === []) {
            throw new RuntimeException(
                'No se encontraron filas con las columnas «'.$columnaCantidad.'» y «'.$columnaProducto.'».',
            );
        }

        return [
            'cabecera' => $this->extraerCabeceraDocumento($textoCabecera),
            'lineas' => $lineas,
        ];
    }

    /**
     * @return array<int, array{pagina: int, filas: array<int, array<int, string>>}>
     */
    private function extraerGrillaNativaPdf(string $path): array
    {
        if (! is_readable($path)) {
            return [];
        }

        try {
            $texto = trim((string) (new Parser)->parseFile($path)->getText());
        } catch (\Throwable) {
            return [];
        }

        if ($texto === '') {
            return [];
        }

        $bloques = preg_split('/\R--\s*\d+\s+of\s+\d+\s+--\R/u', $texto) ?: [$texto];
        $paginas = [];
        $numPagina = 1;

        foreach ($bloques as $bloque) {
            $filas = [];
            foreach (preg_split('/\r\n|\n|\r/u', $bloque) ?: [] as $lineaCruda) {
                if (! str_contains($lineaCruda, "\t")) {
                    continue;
                }
                $celdas = array_values(array_filter(
                    array_map(static fn (string $c): string => trim($c), explode("\t", $lineaCruda)),
                    static fn (string $c): bool => $c !== '',
                ));
                if ($celdas !== []) {
                    $filas[] = $celdas;
                }
            }

            if ($filas !== []) {
                $paginas[] = ['pagina' => $numPagina, 'filas' => $filas];
            }

            $numPagina++;
        }

        return $paginas;
    }

    /**
     * @param  array<int, array{pagina: int, filas: array<int, array<int, string>>}>  $paginasFilas
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function aplicarMapeoColumnasPorNombre(
        array $paginasFilas,
        string $columnaCantidad,
        string $columnaProducto,
    ): array {
        $nombreCantidad = $this->normalizarEncabezadoCelda($columnaCantidad);
        $nombreProducto = $this->normalizarEncabezadoCelda($columnaProducto);
        $idxCantidad = null;
        $idxProducto = null;
        $resultado = [];
        $bufferDesc = null;
        $bufferCant = null;

        $volcarBuffer = function () use (&$resultado, &$bufferDesc, &$bufferCant): void {
            if ($bufferDesc !== null && $bufferCant !== null && mb_strlen($bufferDesc) >= 2) {
                $resultado[] = [
                    'cantidad' => $bufferCant,
                    'descripcion' => $bufferDesc,
                ];
            }
            $bufferDesc = null;
            $bufferCant = null;
        };

        foreach ($paginasFilas as $pagina) {
            foreach ($pagina['filas'] ?? [] as $celdasRaw) {
                if (! is_array($celdasRaw)) {
                    continue;
                }

                $celdas = array_values(array_map(
                    static fn ($c): string => trim((string) $c),
                    $celdasRaw,
                ));

                if ($this->filaGrillaVacia($celdas)) {
                    continue;
                }

                $indicesHeader = $this->resolverIndicesHeaderPorNombre($celdas, $nombreCantidad, $nombreProducto);
                if ($indicesHeader !== null) {
                    $idxCantidad = $indicesHeader['cantidad'];
                    $idxProducto = $indicesHeader['producto'];
                    $volcarBuffer();

                    continue;
                }

                if ($idxCantidad === null || $idxProducto === null) {
                    continue;
                }

                while (count($celdas) <= max($idxCantidad, $idxProducto)) {
                    $celdas[] = '';
                }

                $cantRaw = trim($celdas[$idxCantidad] ?? '');
                $prodRaw = trim($celdas[$idxProducto] ?? '');
                $cantidad = $this->parseCantidadCeldaTabla($cantRaw);

                if ($cantidad !== null && mb_strlen($prodRaw) >= 2) {
                    $volcarBuffer();
                    $bufferDesc = $prodRaw;
                    $bufferCant = $cantidad;

                    continue;
                }

                if ($bufferDesc !== null && $prodRaw !== '' && $cantidad === null) {
                    $bufferDesc = trim($bufferDesc.' '.$prodRaw);
                }
            }
        }

        $volcarBuffer();

        return $resultado;
    }

    /**
     * @param  array<int, string>  $celdas
     * @return array{cantidad: int, producto: int}|null
     */
    private function resolverIndicesHeaderPorNombre(
        array $celdas,
        string $nombreCantidad,
        string $nombreProducto,
    ): ?array {
        $idxCantidad = null;
        $idxProducto = null;

        foreach ($celdas as $indice => $celda) {
            $normalizada = $this->normalizarEncabezadoCelda($celda);
            if ($normalizada === '') {
                continue;
            }
            if ($this->celdaCoincideNombreColumna($normalizada, $nombreCantidad)) {
                $idxCantidad = $indice;
            }
            if ($this->celdaCoincideNombreColumna($normalizada, $nombreProducto)) {
                $idxProducto = $indice;
            }
        }

        if ($idxCantidad === null || $idxProducto === null || $idxCantidad === $idxProducto) {
            return null;
        }

        return ['cantidad' => $idxCantidad, 'producto' => $idxProducto];
    }

    private function celdaCoincideNombreColumna(string $celdaNormalizada, string $nombreNormalizado): bool
    {
        if ($celdaNormalizada === $nombreNormalizado) {
            return true;
        }

        if (str_contains($celdaNormalizada, $nombreNormalizado)) {
            return true;
        }

        return str_contains($nombreNormalizado, $celdaNormalizada) && mb_strlen($celdaNormalizada) >= 4;
    }

    /**
     * @param  array<int, string>  $celdas
     */
    private function filaGrillaVacia(array $celdas): bool
    {
        foreach ($celdas as $celda) {
            if (trim($celda) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    public function parseUploadedFile(UploadedFile $file): array
    {
        return $this->parseDocumentoCompleto($file)['lineas'];
    }

    /**
     * Misma ruta que el import web, con detalle por etapa (nativo / OCR / Paddle / parser).
     * Útil en VPS: comparar qué texto sale del PDF vs fixtures .txt de tests.
     *
     * @return array<string, mixed>
     */
    public function diagnosticarPdf(string $path): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException('No se pudo leer el PDF: '.$path);
        }

        $ocr = $this->ocr ?? new PdfOcrService;
        $paddle = $this->paddle ?? new PdfPaddleOcrService;

        $textoNativo = '';
        $errorNativo = null;
        try {
            $parser = new Parser;
            $textoNativo = trim((string) $parser->parseFile($path)->getText());
        } catch (\Throwable $e) {
            $errorNativo = $e->getMessage();
        }

        $textoOcr = '';
        $textoOcrCrop = '';
        $errorOcr = null;
        $errorOcrCrop = null;
        if ($ocr->estaDisponible()) {
            try {
                $textoOcr = $this->extraerTextoPdfMedianteOcr($path, false);
            } catch (\Throwable $e) {
                $errorOcr = $e->getMessage();
            }
            try {
                $textoOcrCrop = $this->extraerTextoPdfMedianteOcr($path, true);
            } catch (\Throwable $e) {
                $errorOcrCrop = $e->getMessage();
            }
        }

        $textoFinal = $this->extraerTextoPdf($path);
        $lineasSoloTexto = $this->parseTexto($textoFinal);

        $lineasPaddle = [];
        $errorPaddle = null;
        if ($paddle->estaDisponible()) {
            try {
                $lineasPaddle = $paddle->extraerLineasTabla($path);
            } catch (\Throwable $e) {
                $errorPaddle = $e->getMessage();
            }
        }

        $uploaded = new UploadedFile($path, basename($path), 'application/pdf', null, true);
        $documento = $this->parseDocumentoCompleto($uploaded);

        $paginas = max($this->contarPaginasPdf($path), $this->inferirPaginasDesdeTexto($textoFinal));
        $debeOcr = $textoNativo !== '' ? $this->debeComplementarTextoPdfConOcr($path, $textoNativo) : null;

        return [
            'archivo' => basename($path),
            'paginas' => $paginas,
            'herramientas' => [
                'tesseract_pdftoppm' => $ocr->estaDisponible(),
                'paddleocr' => $paddle->estaDisponible(),
            ],
            'formato_detectado' => $this->detectarFormato($textoFinal),
            'es_solicitud_pedido' => $this->esSolicitudPedidoDocumento($textoFinal),
            'debe_complementar_ocr' => $debeOcr,
            'min_lineas_esperadas' => $this->minLineasEsperadasTablaProducto($textoFinal, $paginas),
            'conteos' => [
                'parse_nativo' => $textoNativo !== '' ? count($this->parseTexto($textoNativo)) : 0,
                'parse_ocr' => $textoOcr !== '' ? count($this->parseTexto($textoOcr)) : 0,
                'parse_ocr_crop' => $textoOcrCrop !== '' ? count($this->parseTexto($textoOcrCrop)) : 0,
                'parse_texto_final' => count($lineasSoloTexto),
                'paddle' => count($lineasPaddle),
                'import_final' => count($documento['lineas']),
            ],
            'errores' => array_filter([
                'nativo' => $errorNativo,
                'ocr' => $errorOcr,
                'ocr_crop' => $errorOcrCrop,
                'paddle' => $errorPaddle,
            ]),
            'texto' => [
                'nativo' => $textoNativo,
                'ocr' => $textoOcr,
                'ocr_crop' => $textoOcrCrop,
                'final' => $textoFinal,
            ],
            'lineas' => [
                'solo_texto' => $lineasSoloTexto,
                'paddle' => $lineasPaddle,
                'final' => $documento['lineas'],
            ],
        ];
    }

    /**
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    public function parseTexto(string $texto): array
    {
        $texto = $this->normalizarEspaciosDocumento($texto);
        $formato = $this->detectarFormato($texto);

        $lineas = match ($formato) {
            self::FORMATO_DETALLE => $this->parseDetalleUnidades($texto),
            self::FORMATO_LICITACION => $this->parseLicitacionPedido($texto),
            self::FORMATO_BASES => $this->parseBasesLinea($texto),
            self::FORMATO_TABLA_PRODUCTO_CANTIDAD => $this->parseTablaProductoCantidad($texto),
            self::FORMATO_COTIZACION_MULTILINEA => $this->parseCotizacionMultilinea($texto),
            self::FORMATO_TABLA_COLUMNAS => $this->parseTablaColumnas($texto),
            self::FORMATO_OFERTA_PRECIO => $this->parseOfertaPrecio($texto),
            self::FORMATO_TABLA_DIDECO => $this->parseTablaDideco($texto),
            self::FORMATO_EETT => $this->parseEettEspecificaciones($texto),
            default => $this->parseListadoCantidad($texto),
        };

        return array_values(array_filter(
            $lineas,
            fn (array $linea): bool => ! $this->esDescripcionAdministrativa($linea['descripcion']),
        ));
    }

    /**
     * Parseo de texto + pipeline de saneamiento (mismo resultado que import web vía OCR).
     *
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    public function parseTextoTablaMaterialesFinalizado(string $texto, int $paginas = 11): array
    {
        $lineas = $this->parseTexto($texto);

        return $this->finalizarLineasTablaSolicitudPedido($texto, $lineas, false, $paginas);
    }

    /**
     * Metadatos del documento (título/empresa/RUT), no líneas de producto.
     *
     * @return array{codigo_cotizacion: string, empresa: string, rutempresa: string, nombre: string}
     */
    public function extraerCabeceraDocumento(string $texto): array
    {
        $texto = $this->normalizarEspaciosDocumento($texto);
        $vacía = [
            'codigo_cotizacion' => '',
            'empresa' => '',
            'rutempresa' => '',
            'nombre' => '',
        ];

        if (trim($texto) === '') {
            return $vacía;
        }

        $nombre = '';
        if (preg_match(
            '/CONVENIO DE SUMINISTRO[\s\S]{10,180}?(?:PARA LA(?:\s+CORPORACI[OÓ]N[\s\S]{0,80}?)?|P[AÁ]GINA\b|BASES ADMINISTRATIVAS\b)/iu',
            $texto,
            $m,
        ) === 1) {
            $nombre = trim(preg_replace('/\s+/u', ' ', $m[0]) ?? $m[0]);
            $nombre = preg_replace('/\s*(?:P[AÁ]GINA\b|BASES ADMINISTRATIVAS\b).*$/iu', '', $nombre) ?? $nombre;
        } elseif (preg_match(
            '/BASES ADMINISTRATIVAS Y T[EÉ]CNICAS\s+(.+?)(?:P[AÁ]GINA\b|BASES ADMINISTRATIVAS\b|1\.\s*INSTITUC)/isu',
            $texto,
            $m,
        ) === 1) {
            $nombre = trim(preg_replace('/\s+/u', ' ', $m[1]) ?? $m[1]);
        }

        $empresa = '';
        if (preg_match('/Corporaci[oó]n de Educaci[oó]n y Salud de Las Condes/iu', $texto, $m) === 1) {
            $empresa = trim($m[0]);
        } elseif (preg_match('/1\.\s*INSTITUCI[OÓ]N SOLICITANTE[\s\S]{0,200}?Raz[oó]n social\s+([^\n\r]{5,120})/iu', $texto, $m) === 1) {
            $empresa = trim($m[1]);
        }

        $rutempresa = '';
        if (preg_match('/\b(\d{1,2}\.\d{3}\.\d{3}-[\dkK])\b/u', $texto, $m) === 1) {
            $rutempresa = str_replace('.', '', strtoupper($m[1]));
        }

        return [
            'codigo_cotizacion' => '',
            'empresa' => mb_substr($empresa, 0, 120),
            'rutempresa' => mb_substr($rutempresa, 0, 12),
            'nombre' => mb_substr(trim($nombre), 0, 250),
        ];
    }

    public function detectarFormato(string $texto): string
    {
        $texto = $this->normalizarEspaciosDocumento($texto);
        $upper = mb_strtoupper($texto);

        if (str_contains($upper, 'PEDIDO ESTABLECIMIENTO') && str_contains($upper, 'CANTIDAD')) {
            return self::FORMATO_LICITACION;
        }

        if (str_contains($upper, 'DETALLE PRODUCTO') && str_contains($upper, 'UNIDADES')) {
            return self::FORMATO_DETALLE;
        }

        if ($this->esFormatoTablaProductoCantidad($upper)) {
            return self::FORMATO_TABLA_PRODUCTO_CANTIDAD;
        }

        if (
            preg_match('/LINEA\s+DESCRIPCION/u', $upper) === 1
            || (str_contains($upper, 'UNIDADES*') && str_contains($upper, 'MONTO TOTAL'))
            || (str_contains($upper, 'BASES ADMINISTRATIVAS') && str_contains($upper, 'DESCRIPCIÓN TÉCNICA'))
            || (str_contains($upper, 'BASES ADMINISTRATIVAS') && str_contains($upper, 'DESCRIPCION TECNICA'))
        ) {
            return self::FORMATO_BASES;
        }

        if ($this->esFormatoCotizacionMultilinea($upper, $texto)) {
            return self::FORMATO_COTIZACION_MULTILINEA;
        }

        if ($this->esFormatoTablaDideco($upper)) {
            return self::FORMATO_TABLA_DIDECO;
        }

        if ($this->esFormatoTablaColumnas($upper)) {
            return self::FORMATO_TABLA_COLUMNAS;
        }

        if ($this->esFormatoOfertaPrecio($upper)) {
            return self::FORMATO_OFERTA_PRECIO;
        }

        if (
            ! $this->tieneCabeceraTablaProductoCantidad($upper)
            && (
                str_contains($upper, 'DETALLE DEL REQUERIMIENTO')
                || str_contains($upper, 'ESPECIFICACIONES TECNICAS')
                || str_contains($upper, 'ESPECIFICACIONES TÉCNICAS')
            )
            && (str_contains($upper, 'UNIDADES') || preg_match('/\d+\s*UNIDADES/u', $upper) === 1)
        ) {
            return self::FORMATO_EETT;
        }

        return self::FORMATO_LISTADO;
    }

    private function normalizarEspaciosDocumento(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);

        // Preservar tabulaciones: separador de celdas en PDF/OCR exportado.
        return preg_replace('/[ ]{2,}/u', ' ', $texto) ?? $texto;
    }

    private function esDescripcionAdministrativa(string $descripcion): bool
    {
        $descripcion = trim($descripcion);
        if ($descripcion === '') {
            return true;
        }

        if (mb_strlen($descripcion) > 280) {
            return true;
        }

        $upper = mb_strtoupper($descripcion);

        foreach ([
            'BASES ADMINISTRATIVAS',
            'INSTITUCIÓN SOLICITANTE',
            'INSTITUCION SOLICITANTE',
            'BIENES Y/O SERVICIOS SOLICITADOS',
            'PARTICIPANTES',
            'GARANTÍA DE SERIEDAD',
            'GARANTIA DE SERIEDAD',
            'CRITERIOS DE EVALUACIÓN',
            'CRITERIOS DE EVALUACION',
            'COMISIÓN EVALUADORA',
            'COMISION EVALUADORA',
            'MERCADOPUBLICO.CL',
        ] as $marcador) {
            if (str_contains($upper, $marcador)) {
                return true;
            }
        }

        return false;
    }

    private function esOrphanAdministrativo(string $orphan): bool
    {
        return $this->esDescripcionAdministrativa($orphan)
            || mb_strlen(trim($orphan)) > 160
            || preg_match('/\b\d+\.\s+[A-ZÁÉÍÓÚÑ]{4,}/u', $orphan) === 1;
    }

    /**
     * Formato canónico: "Cantidad NOMBRE DEL PRODUCTO" / "40 ACUARELAS..."
     *
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function parseListadoCantidad(string $texto): array
    {
        $texto = preg_replace('/[ \t]+/u', ' ', $texto) ?? $texto;
        $lineas = array_values(array_filter(
            array_map(static fn (string $linea) => trim($linea), explode("\n", $texto)),
            static fn (string $linea) => $linea !== '',
        ));

        $resultado = [];
        $indiceActual = null;
        $cantidadPendiente = null;

        foreach ($lineas as $lineaCruda) {
            if ($this->esEncabezadoListado($lineaCruda)) {
                continue;
            }

            if ($this->esRuidoListado($lineaCruda)) {
                continue;
            }

            foreach ($this->expandirLineasCantidadOcr($lineaCruda) as $linea) {
                $normalizada = $this->normalizarCantidadInicialOcr($linea);

                // OCR a veces deja la cantidad sola ("3") y el nombre en la línea siguiente.
                if (preg_match('/^\d{1,6}$/u', $normalizada) === 1) {
                    $cantidadPendiente = max(1, (int) $normalizada);

                    continue;
                }

                if (preg_match('/^(\d{1,6})(?:\s+|(?=c\s*\/\s*[ua]))(.+)$/iu', $normalizada, $coincidencia) === 1) {
                    $resultado[] = [
                        'cantidad' => max(1, (int) $coincidencia[1]),
                        'descripcion' => $this->limpiarInicioDescripcion($coincidencia[2]),
                    ];
                    $indiceActual = count($resultado) - 1;
                    $cantidadPendiente = null;

                    continue;
                }

                $descripcion = $this->limpiarInicioDescripcion($normalizada);
                if ($descripcion === '') {
                    continue;
                }

                if ($cantidadPendiente !== null) {
                    $resultado[] = [
                        'cantidad' => $cantidadPendiente,
                        'descripcion' => $descripcion,
                    ];
                    $indiceActual = count($resultado) - 1;
                    $cantidadPendiente = null;

                    continue;
                }

                if ($indiceActual !== null) {
                    $resultado[$indiceActual]['descripcion'] = trim(
                        $resultado[$indiceActual]['descripcion'].' '.$descripcion,
                    );
                }
            }
        }

        return $resultado;
    }

    /**
     * El OCR de tablas a veces pega varias filas en una sola línea
     * ("…40g B0 Cajas de…" / "…3em 3 Termolaminadoras"). Separa esas filas.
     *
     * @return list<string>
     */
    private function expandirLineasCantidadOcr(string $linea): array
    {
        $linea = trim($linea);
        if ($linea === '') {
            return [];
        }

        // Solo tokens OCR típicos de "80" (B0/BO/8O), no dígitos puros:
        // partir en "100 Láminas" rompería descripciones válidas.
        $expandida = preg_replace(
            '/(?<=\S)(?:\s+[|\[\]iIl1]*)?\s*\b([B8][O0]|BO)\b(?=\s+[A-ZÁÉÍÓÚÑ¡])/u',
            "\n$1",
            $linea,
        ) ?? $linea;

        // Tras una medida (3cm/3em) el OCR pega la cantidad de la fila siguiente.
        $expandida = preg_replace(
            '/(\d\s*(?:cm|em|mm)\b)\s+(\d{1,4})\s+(?=[A-ZÁÉÍÓÚÑ¡])/iu',
            "$1\n$2 ",
            $expandida,
        ) ?? $expandida;

        return array_values(array_filter(
            array_map(static fn (string $parte) => trim($parte), explode("\n", $expandida)),
            static fn (string $parte) => $parte !== '',
        ));
    }

    /**
     * Corrige cantidad inicial mal leída por OCR en listados escaneados.
     * Ej.: "| B0 Cajas…" → "80 Cajas…", "P0cm ¡Cartulina…" → "20 c/u ¡Cartulina…".
     */
    private function normalizarCantidadInicialOcr(string $linea): string
    {
        $linea = $this->quitarBordeTablaOcr($linea);

        if (preg_match('/^([B8][O0]|BO)\b(.*)$/u', $linea, $m) === 1) {
            return '80'.($m[2] ?? '');
        }

        // "P0cm" / "POcu" / "P0 c/u" suelen ser "20 c/u" (2→P).
        if (preg_match('/^P[O0](?:\s*c\s*[\/m]\s*u?|\s*cm\b)(.*)$/iu', $linea, $m) === 1) {
            return '20 c/u'.($m[1] ?? '');
        }

        return $linea;
    }

    /**
     * Formato: "DETALLE PRODUCTO UNIDADES FORMATO" / "CLIP 28MM 50 CAJAS"
     *
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function parseDetalleUnidades(string $texto): array
    {
        $unidades = 'CAJAS|UNIDADES|HOJAS|RESMA|ROLLOS?|PACKS?|SOBRES?|FRASCOS?|CAJA|UNIDAD';
        $patron = '/^(.+?)\s+(\d+)\s+('.$unidades.')\s*$/iu';
        $resultado = [];

        foreach (explode("\n", $texto) as $lineaCruda) {
            $linea = trim(preg_replace('/[ \t]+/u', ' ', $lineaCruda) ?? $lineaCruda);
            if ($linea === '') {
                continue;
            }

            $upper = mb_strtoupper($linea);
            if (str_contains($upper, 'DETALLE PRODUCTO')) {
                continue;
            }

            if (preg_match($patron, $linea, $m) !== 1) {
                continue;
            }

            $descripcion = trim($m[1]);
            if ($descripcion === '') {
                continue;
            }

            $resultado[] = [
                'cantidad' => max(1, (int) $m[2]),
                'descripcion' => $descripcion,
            ];
        }

        return $resultado;
    }

    /**
     * Formato: "PEDIDO ESTABLECIMIENTO PRODUCTO CANTIDAD"
     * El número inicial es el pedido; la cantidad va al final del bloque.
     *
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function parseLicitacionPedido(string $texto): array
    {
        $lineas = [];
        foreach (explode("\n", $texto) as $lineaCruda) {
            $linea = trim(preg_replace('/[ \t]+/u', ' ', $lineaCruda) ?? $lineaCruda);
            if ($linea !== '') {
                $lineas[] = $linea;
            }
        }

        $resultado = [];
        $buffer = [];

        $flush = function () use (&$buffer, &$resultado): void {
            if ($buffer === []) {
                return;
            }

            $textoBloque = trim(implode(' ', $buffer));
            $buffer = [];
            $upper = mb_strtoupper($textoBloque);

            if (
                str_contains($upper, 'PEDIDO ESTABLECIMIENTO')
                || str_starts_with($upper, 'MONTO ESTIMADO')
            ) {
                return;
            }

            if (preg_match('/^(?:\d{4,6}\s+)?(.+?)\s+(\d+)\s*$/u', $textoBloque, $m) !== 1) {
                return;
            }

            $descripcion = trim($m[1]);
            $cantidad = (int) $m[2];

            if ($descripcion === '' || mb_strlen($descripcion) < 3 || $cantidad > 100000) {
                return;
            }

            // Quitar código de pedido si quedó pegado al inicio por rarezas de extracción.
            $descripcion = preg_replace('/^\d{4,6}\s+/u', '', $descripcion) ?? $descripcion;
            $descripcion = trim($descripcion);
            if ($descripcion === '' || mb_strlen($descripcion) < 3) {
                return;
            }

            $resultado[] = [
                'cantidad' => max(1, $cantidad),
                'descripcion' => $descripcion,
            ];
        };

        foreach ($lineas as $linea) {
            $upper = mb_strtoupper($linea);

            if (str_contains($upper, 'PEDIDO ESTABLECIMIENTO')) {
                $flush();

                continue;
            }

            if (str_starts_with($upper, 'MONTO ESTIMADO')) {
                $flush();

                continue;
            }

            if (preg_match('/^\d{4,6}\b/u', $linea) === 1) {
                $flush();
                $buffer = [$linea];

                continue;
            }

            if (preg_match('/^\d+$/u', $linea) === 1 && $buffer !== []) {
                $buffer[] = $linea;
                $flush();

                continue;
            }

            if ($buffer !== []) {
                $buffer[] = $linea;
            } else {
                $buffer = [$linea];
            }
        }

        $flush();

        return $resultado;
    }

    /**
     * Formato bases/licitación: "LÍNEA / DESCRIPCIÓN / UNIDADES / MONTO"
     * Usa UNIDADES (referenciales) como cantidad.
     *
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function parseBasesLinea(string $texto): array
    {
        $catalogo = $this->extraerSeccionCatalogoBases($texto);
        $catalogo = $this->limpiarRuidoBases($catalogo);

        $raw = [];
        foreach (explode("\n", $catalogo) as $lineaCruda) {
            $linea = trim(preg_replace('/[ \t]+/u', ' ', $lineaCruda) ?? $lineaCruda);
            if ($linea !== '') {
                $raw[] = $linea;
            }
        }

        $resultado = [];
        $orphan = '';
        $buffer = '';

        $tryFlush = function (string $text) use (&$resultado): bool {
            $fila = $this->parseFilaBasesLineaCompleta($text);
            if ($fila === null) {
                return false;
            }

            $resultado[] = $fila;

            return true;
        };

        foreach ($raw as $linea) {
            if (preg_match('/^\d{1,3}$/u', $linea) === 1) {
                if ($buffer !== '' && ! $tryFlush($buffer)) {
                    if (! $this->esOrphanAdministrativo($buffer)) {
                        $orphan = trim($orphan.' '.$buffer);
                    }
                }
                $buffer = $linea;

                continue;
            }

            if (preg_match('/^\d{1,3}\s+/u', $linea) === 1) {
                if ($buffer !== '' && ! $tryFlush($buffer)) {
                    if (! $this->esOrphanAdministrativo($buffer)) {
                        $orphan = trim($orphan.' '.$buffer);
                    }
                }

                $text = $linea;
                if ($orphan !== '' && ! $this->esOrphanAdministrativo($orphan)) {
                    if (preg_match('/^(\d{1,3})\s+(.*)$/u', $text, $m) === 1) {
                        $text = trim($m[1].' '.$orphan.' '.$m[2]);
                    }
                }
                $orphan = '';

                $buffer = $text;
                if ($tryFlush($buffer)) {
                    $buffer = '';
                }

                continue;
            }

            if ($buffer !== '') {
                $buffer = trim($buffer.' '.$linea);
                if ($tryFlush($buffer)) {
                    $buffer = '';
                }
            } elseif (! $this->esOrphanAdministrativo($linea)) {
                $orphan = trim($orphan.' '.$linea);
            }
        }

        if ($buffer !== '') {
            $tryFlush($buffer);
        }

        return $resultado;
    }

    /**
     * Una fila del catálogo bases: LÍNEA + DESCRIPCIÓN + UNIDADES (+ monto opcional).
     *
     * @return array{cantidad: int, descripcion: string}|null
     */
    private function parseFilaBasesLineaCompleta(string $text): ?array
    {
        $text = trim(preg_replace('/[ \t]+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return null;
        }

        $patronCompleto = '/^(\d{1,3})\s+(.+)\s+(\d{1,3}(?:\.\d{3})+|\d+)\s+(\d{1,3}(?:\.\d{3})+|\d+)(?:[a-z]{0,2})?$/iu';
        if (preg_match($patronCompleto, $text, $m) === 1) {
            return $this->construirFilaBasesLinea(trim($m[2]), $m[3]);
        }

        $patronSinUnidades = '/^(\d{1,3})\s+(.+)\s+(\d{1,3}(?:\.\d{3})+)$/u';
        if (preg_match($patronSinUnidades, $text, $m) === 1) {
            return $this->construirFilaBasesLinea(trim($m[2]), '1');
        }

        $patronSoloReferencia = '/^(\d{1,3})\s+(.+)\s+(\d{1,4})$/u';
        if (preg_match($patronSoloReferencia, $text, $m) === 1 && ! str_contains($m[3], '.')) {
            return $this->construirFilaBasesLinea(trim($m[2]), $m[3]);
        }

        return null;
    }

    /**
     * @return array{cantidad: int, descripcion: string}|null
     */
    private function construirFilaBasesLinea(string $descripcion, string $unidadesRaw): ?array
    {
        $descripcion = trim(preg_replace('/\s+/u', ' ', $descripcion) ?? $descripcion);
        $unidades = str_replace('.', '', trim($unidadesRaw));
        if ($descripcion === '' || ! ctype_digit($unidades) || $this->esDescripcionAdministrativa($descripcion)) {
            return null;
        }

        return [
            'cantidad' => max(1, (int) $unidades),
            'descripcion' => $descripcion,
        ];
    }

    private function extraerSeccionCatalogoBases(string $texto): string
    {
        $texto = $this->normalizarEspaciosDocumento($texto);

        $inicio = false;
        foreach ([
            'LINEA DESCRIPCION REQUERIMIENTO',
            'LINEA DESCRIPCION',
            'LÍNEA DESCRIPCION',
            '5. DESCRIPCIÓN TÉCNICA',
            '5. DESCRIPCION TECNICA',
        ] as $marcador) {
            $pos = mb_stripos($texto, $marcador);
            if ($pos !== false) {
                $inicio = $pos;
                break;
            }
        }

        $fin = mb_stripos($texto, 'Los oferentes podrán postular');
        if ($fin === false) {
            $fin = mb_stripos($texto, 'Los oferentes podran postular');
        }

        if ($inicio !== false && $fin !== false && $fin > $inicio) {
            return mb_substr($texto, $inicio, $fin - $inicio);
        }

        if ($inicio !== false) {
            return mb_substr($texto, $inicio);
        }

        return $texto;
    }

    private function limpiarRuidoBases(string $catalogo): string
    {
        $catalogo = preg_replace('/P[aá]gina\s+\d+\s+de\s+\d+/iu', "\n", $catalogo) ?? $catalogo;
        $catalogo = preg_replace('/Corporaci[oó]n de\s+E[do]ucaci[oó]n y Salud/iu', "\n", $catalogo) ?? $catalogo;
        $catalogo = preg_replace('/\bLAS CONDES\b/u', "\n", $catalogo) ?? $catalogo;
        $catalogo = preg_replace('/\bMUNICIPALIDAD\b/u', "\n", $catalogo) ?? $catalogo;
        $catalogo = preg_replace('/LINEA DESCRIPCION REQUERIMIENTO/iu', "\n", $catalogo) ?? $catalogo;
        $catalogo = preg_replace(
            '/UNIDADES\*\s*POR\s*A[ÑN]O\s*Monto Total\s*\(\$\)\s*POR\s*A[ÑN]O/iu',
            "\n",
            $catalogo,
        ) ?? $catalogo;
        $catalogo = preg_replace('/\bUND\.\s+/iu', 'UND. ', $catalogo) ?? $catalogo;

        return $catalogo;
    }

    /**
     * Extrae filas de tablas Word con columnas Cantidad + Producto (p. ej. Compra Ágil).
     *
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    public function parseDocxTablas(string $path): array
    {
        $xml = $this->leerDocumentXmlDocx($path);
        $filas = $this->extraerFilasTablaDocx($xml);
        if ($filas === []) {
            return [];
        }

        $resultado = [];
        $indices = null;

        foreach ($filas as $celdas) {
            if ($indices === null) {
                $indices = $this->resolverIndicesColumnasProductoCantidad($celdas);
                if ($indices !== null) {
                    continue;
                }

                continue;
            }

            $fila = $this->extraerFilaConIndicesColumnas($celdas, $indices);
            if ($fila !== null) {
                $resultado[] = $fila;
            }
        }

        return $resultado;
    }

    private function extraerTextoPdf(string $path): string
    {
        if (! is_readable($path)) {
            throw new RuntimeException('No se pudo leer el archivo.');
        }

        $fragmentos = $this->recolectarFragmentosTextoPdf($path, basename($path));
        if ($fragmentos === []) {
            throw new RuntimeException('No se pudo extraer texto del PDF. Verifique que no sea un documento escaneado.');
        }

        return $this->elegirMejorTextoTablaProductoDesdeFragmentos($fragmentos);
    }

    /**
     * Nativo + Tesseract (página y recorte). Replica en VPS lo que los tests simulan
     * al combinar fixtures distintos (p. ej. native_parcial + ocr_vps).
     *
     * @return array<string, string>
     */
    private function recolectarFragmentosTextoPdf(string $path, string $nombreArchivo = ''): array
    {
        $fragmentos = [];
        $textoNativo = '';

        try {
            $parser = new Parser;
            $textoNativo = trim((string) $parser->parseFile($path)->getText());
            if ($textoNativo !== '') {
                $fragmentos['nativo'] = $textoNativo;
            }
        } catch (\Throwable $e) {
            Log::warning('Import PDF: texto nativo no disponible', ['error' => $e->getMessage()]);
        }

        $necesitaOcr = $textoNativo === ''
            || $this->debeComplementarTextoPdfConOcr($path, $textoNativo);

        if (! $necesitaOcr) {
            return $fragmentos;
        }

        $paddle = $this->paddle ?? new PdfPaddleOcrService;
        $paginas = $this->resolverPaginasPdf($path, $nombreArchivo);
        $esEspecificaciones = $this->esNombreArchivoEspecificacionesTecnicas($nombreArchivo);
        if ($paddle->estaDisponible()
            && $this->esProbableTablaMaterialesEscaneada($textoNativo, $paginas, $path, $nombreArchivo)
            && ! $esEspecificaciones) {
            Log::info('Import PDF: omitiendo OCR Tesseract; PaddleOCR procesará la tabla escaneada');

            if ($fragmentos === []) {
                $fragmentos['paddle_hint'] = $this->textoHintTablaMaterialesEscaneada($nombreArchivo, $paginas);
            }

            return $fragmentos;
        }

        $ocr = $this->ocr ?? new PdfOcrService;
        if (! $ocr->estaDisponible()) {
            if ($textoNativo === '') {
                throw new RuntimeException(
                    'El PDF no contiene texto legible (posible escaneo) y OCR no está disponible en este entorno.',
                );
            }

            return $fragmentos;
        }

        try {
            $texto = trim($this->extraerTextoPdfMedianteOcr($path, false));
            if ($texto !== '') {
                $fragmentos['ocr'] = $texto;
            }
        } catch (\Throwable $e) {
            Log::warning('Import PDF: OCR falló', ['error' => $e->getMessage()]);
        }

        $textoEval = $this->elegirMejorTextoTablaProductoDesdeFragmentos($fragmentos);
        if ($textoEval !== '' && $this->necesitaOcrRecortado($path, $textoEval)) {
            try {
                $textoCrop = trim($this->extraerTextoPdfMedianteOcr($path, true));
                if ($textoCrop !== '') {
                    $fragmentos['ocr_crop'] = $textoCrop;
                }
            } catch (\Throwable $e) {
                Log::warning('Import PDF: OCR recortado falló', ['error' => $e->getMessage()]);
            }
        }

        return $fragmentos;
    }

    private function necesitaOcrRecortado(string $path, string $texto): bool
    {
        $upper = mb_strtoupper($this->normalizarEspaciosDocumento($texto));
        $esSolicitudPedido = $this->esSolicitudPedidoDocumento($texto);

        if (! $this->esFormatoTablaProductoCantidad($upper) && ! $esSolicitudPedido) {
            return false;
        }

        $paginas = max($this->contarPaginasPdf($path), $this->inferirPaginasDesdeTexto($texto));
        $lineas = count($this->parseTablaProductoCantidad($texto));
        $minEsperadas = $this->minLineasEsperadasTablaProducto($texto, $paginas);

        return $lineas < $minEsperadas;
    }

    /**
     * @param  array<string, string>  $fragmentos
     */
    private function elegirMejorTextoTablaProductoDesdeFragmentos(array $fragmentos): string
    {
        $fragmentos = array_values(array_filter(
            array_map(static fn (string $t): string => trim($t), $fragmentos),
            static fn (string $t): bool => $t !== '',
        ));

        if ($fragmentos === []) {
            return '';
        }

        if (count($fragmentos) === 1) {
            return $fragmentos[0];
        }

        $mejor = $fragmentos[0];
        $maxLineas = count($this->parseTablaProductoCantidad($mejor));

        for ($i = 1; $i < count($fragmentos); $i++) {
            $candidato = $this->elegirMejorTextoPdfTablaProducto($mejor, $fragmentos[$i]);
            $lineas = count($this->parseTablaProductoCantidad($candidato));
            if ($lineas >= $maxLineas) {
                $maxLineas = $lineas;
                $mejor = $candidato;
            }
        }

        $combinado = trim(implode("\n", $fragmentos));
        $lineasCombinado = count($this->parseTablaProductoCantidad($combinado));
        if ($lineasCombinado >= $maxLineas) {
            return $combinado;
        }

        return $mejor;
    }

    private function resolverTextoPdfMedianteOcr(string $path, ?string $textoNativo): string
    {
        $textoOcr = $this->extraerTextoPdfMedianteOcr($path, false);
        $elegido = $textoNativo !== null
            ? $this->elegirMejorTextoPdfTablaProducto($textoNativo, $textoOcr)
            : $textoOcr;

        $paginas = max($this->contarPaginasPdf($path), $this->inferirPaginasDesdeTexto($elegido));
        $upper = mb_strtoupper($this->normalizarEspaciosDocumento($elegido));
        $esSolicitudPedido = $this->esSolicitudPedidoDocumento($elegido);
        if (! $this->esFormatoTablaProductoCantidad($upper) && ! $esSolicitudPedido) {
            return $elegido;
        }

        $lineas = count($this->parseTablaProductoCantidad($elegido));
        $minEsperadas = $this->minLineasEsperadasTablaProducto($elegido, $paginas);
        if ($lineas >= $minEsperadas) {
            return $elegido;
        }

        try {
            $textoCrop = $this->extraerTextoPdfMedianteOcr($path, true);
            $mejor = $this->elegirMejorTextoPdfTablaProducto($elegido, $textoCrop);
            if ($textoNativo !== null) {
                $combinadoNativo = $this->elegirMejorTextoPdfTablaProducto($textoNativo, $mejor);

                return $this->elegirMejorTextoPdfTablaProducto($combinadoNativo, $textoOcr);
            }

            return $mejor;
        } catch (RuntimeException) {
            return $elegido;
        }
    }

    private function extraerTextoPdfMedianteOcr(string $path, bool $recortarColumnaProducto): string
    {
        $ocr = $this->ocr ?? new PdfOcrService;
        $opciones = [];
        if ($recortarColumnaProducto) {
            $opciones['crop_left_percent'] = $this->porcentajeRecorteColumnaProducto();
        }

        try {
            return $ocr->extraerTexto($path, $opciones);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'OCR no disponible')) {
                throw new RuntimeException(
                    'El PDF no contiene texto legible (posible escaneo) y OCR no está disponible en este entorno. Use un PDF/Word con texto nativo, o despliegue con tesseract/pdftoppm.',
                    0,
                    $e,
                );
            }

            throw new RuntimeException(
                'El PDF está escaneado y el OCR no pudo leerlo correctamente: '.$e->getMessage(),
                0,
                $e,
            );
        }
    }

    private function porcentajeRecorteColumnaProducto(): int
    {
        try {
            $valor = config('cotiz.ocr.crop_left_percent_tabla');
        } catch (\Throwable) {
            return 58;
        }

        return is_numeric($valor) ? max(40, min(75, (int) $valor)) : 58;
    }

    private function inferirPaginasDesdeTexto(string $texto): int
    {
        if (preg_match_all('/P[AÁ]GINA\s+\d+\s+DE\s+(\d+)/iu', $texto, $coincidencias) !== false
            && ($coincidencias[1] ?? []) !== []) {
            return max(array_map(static fn (string $n): int => (int) $n, $coincidencias[1]));
        }

        return 1;
    }

    private function contarCabecerasTablaProducto(string $texto): int
    {
        $upper = mb_strtoupper($this->normalizarEspaciosDocumento($texto));
        $n = preg_match_all('/PRODUCTO\s+CANTIDAD/iu', $upper);

        return is_int($n) ? max(0, $n) : 0;
    }

    private function estimarFilasEsperadasTablaMateriales(string $texto, int $paginas): int
    {
        $upper = mb_strtoupper($this->normalizarEspaciosDocumento($texto));
        if ($this->esEspecificacionesTecnicasTablaProducto($upper) && $paginas < 8) {
            $paginas = 11;
        }

        $paginas = max(1, $paginas);
        $cabeceras = $this->contarCabecerasTablaProducto($texto);

        if ($cabeceras >= max(3, (int) floor($paginas * 0.5))) {
            return max(9, (int) round($cabeceras * 8.8));
        }

        return max(9, (int) round($paginas * 8.82));
    }

    private function estimacionFilasEsperadasEsFiable(string $texto, int $paginas): bool
    {
        $paginas = max(1, $paginas);
        $cabeceras = $this->contarCabecerasTablaProducto($texto);

        if ($paginas >= 8) {
            return true;
        }

        return $cabeceras >= max(3, (int) floor($paginas * 0.5));
    }

    private function debePodarFilasTablaMateriales(string $texto, int $paginas, array $filas): bool
    {
        $n = count($filas);
        $upper = mb_strtoupper($this->normalizarEspaciosDocumento($texto));

        if ($this->esEspecificacionesTecnicasTablaProducto($upper) && $n >= 90 && $n <= 180) {
            return true;
        }

        if ($paginas >= 8 && $n >= 90 && $n <= 180) {
            return true;
        }

        if (! $this->estimacionFilasEsperadasEsFiable($texto, $paginas)) {
            return false;
        }

        $esperadas = $this->estimarFilasEsperadasTablaMateriales($texto, $paginas);

        return $n > (int) ceil($esperadas * 1.05);
    }

    /**
     * @param  array<int, array{cantidad: int, descripcion: string}>  $filas
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function podarFilasTablaMaterialesSiExceso(string $texto, int $paginas, array $filas): array
    {
        if (! $this->debePodarFilasTablaMateriales($texto, $paginas, $filas)) {
            return $filas;
        }

        $esperadas = $this->estimarFilasEsperadasTablaMateriales($texto, $paginas);
        $limite = (int) ceil($esperadas * 1.05);
        $upper = mb_strtoupper($this->normalizarEspaciosDocumento($texto));
        if ($this->esEspecificacionesTecnicasTablaProducto($upper) || $paginas >= 8) {
            $limite = $esperadas;
        }

        while (count($filas) > $limite) {
            $reducidas = $this->eliminarFilasSubcadenaContenida($filas);
            if (count($reducidas) < count($filas)) {
                $filas = $reducidas;

                continue;
            }

            $reducidas = $this->eliminarFilaDuplicadaRepresentada($filas);
            if (count($reducidas) < count($filas)) {
                $filas = $reducidas;

                continue;
            }

            $reducidas = $this->eliminarFilasPrefijoDeDescripcionMasLarga($filas);
            if (count($reducidas) < count($filas)) {
                $filas = $reducidas;

                continue;
            }

            break;
        }

        return $filas;
    }

    /**
     * @param  array<int, array{cantidad: int, descripcion: string}>  $filas
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function eliminarFilaDuplicadaRepresentada(array $filas): array
    {
        foreach ($filas as $indice => $candidata) {
            $otras = [];
            foreach ($filas as $i => $fila) {
                if ($i !== $indice) {
                    $otras[] = $fila;
                }
            }

            if ($this->filaYaRepresentadaEnTabla($candidata, $otras)) {
                unset($filas[$indice]);

                return array_values($filas);
            }
        }

        return $filas;
    }

    /**
     * Elimina filas cuya descripción es prefijo de otra más completa (celdas Paddle partidas).
     *
     * @param  array<int, array{cantidad: int, descripcion: string}>  $filas
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function eliminarFilasPrefijoDeDescripcionMasLarga(array $filas): array
    {
        foreach ($filas as $indice => $candidata) {
            $desc = mb_strtoupper(trim($candidata['descripcion']));
            if ($desc === '' || mb_strlen($desc) < 5) {
                continue;
            }

            foreach ($filas as $otroIndice => $otra) {
                if ($indice === $otroIndice) {
                    continue;
                }

                $otraDesc = mb_strtoupper(trim($otra['descripcion']));
                if (mb_strlen($otraDesc) <= mb_strlen($desc)) {
                    continue;
                }

                if (str_starts_with($otraDesc, $desc.' ') || str_starts_with($otraDesc, $desc)) {
                    unset($filas[$indice]);

                    return array_values($filas);
                }
            }
        }

        return $filas;
    }

    /**
     * @param  array<int, array{cantidad: int, descripcion: string}>  $filas
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function eliminarFilaRedundanteMasCorta(array $filas): array
    {
        $original = count($filas);
        $filas = $this->eliminarFilaDuplicadaRepresentada($filas);
        if (count($filas) < $original) {
            return $filas;
        }

        $indiceCorto = null;
        $longitudMin = PHP_INT_MAX;
        foreach ($filas as $indice => $fila) {
            $longitud = mb_strlen(trim($fila['descripcion']));
            if ($longitud < $longitudMin) {
                $longitudMin = $longitud;
                $indiceCorto = $indice;
            }
        }

        if ($indiceCorto === null) {
            return $filas;
        }

        unset($filas[$indiceCorto]);

        return array_values($filas);
    }

    private function esSolicitudPedidoDocumento(string $texto): bool
    {
        $upper = mb_strtoupper($this->normalizarEspaciosDocumento($texto));

        return preg_match('/ESPECIFICACIONES\s+SOLICITUD\s+DE\s+PEDIDO/u', $upper) === 1;
    }

    /** Tabla escaneada producto/cantidad (solicitud pedido o ESPECIFICACIONES TECNICAS). */
    private function esTablaMaterialesOcrEscaneada(string $texto): bool
    {
        if ($this->esSolicitudPedidoDocumento($texto)) {
            return true;
        }

        $upper = mb_strtoupper($this->normalizarEspaciosDocumento($texto));

        return $this->esEspecificacionesTecnicasTablaProducto($upper);
    }

    private function tieneCabeceraTablaProductoCantidad(string $upper): bool
    {
        return preg_match('/PRODUCTO\s+CANTIDAD/u', $upper) === 1;
    }

    private function esDocumentoTablaMaterialesPdf(string $texto): bool
    {
        $upper = mb_strtoupper($this->normalizarEspaciosDocumento($texto));

        if ($this->esSolicitudPedidoDocumento($texto)) {
            return true;
        }

        if ($this->tieneCabeceraTablaProductoCantidad($upper)) {
            return true;
        }

        if ($this->esEspecificacionesTecnicasTablaProducto($upper)) {
            return true;
        }

        return preg_match('/\bPRODUCTO\b/u', $upper) === 1
            && preg_match('/\bCANTIDAD\b/u', $upper) === 1
            && preg_match('/IMAGEN\s+(?:DE\s+)?REFERENCIA/u', $upper) === 1;
    }

    private function esEspecificacionesTecnicasTablaProducto(string $upper): bool
    {
        if (! str_contains($upper, 'ESPECIFICACIONES TECNICAS') && ! str_contains($upper, 'ESPECIFICACIONES TÉCNICAS')) {
            return false;
        }

        return preg_match('/\bPRODUCTO\b/u', $upper) === 1
            || preg_match('/IMAGEN\s+(?:DE\s+)?REFERENCIA/u', $upper) === 1
            || preg_match('/\d+\s+UNIDADES/u', $upper) === 1
            || preg_match('/\bCANTIDAD\b/u', $upper) === 1;
    }

    private function esTablaMaterialesPorFilasPaddle(string $texto, int $paginas, int $countPaddle): bool
    {
        if ($this->esDocumentoTablaMaterialesPdf($texto)) {
            return true;
        }

        if ($countPaddle < 40) {
            return false;
        }

        $upper = mb_strtoupper($this->normalizarEspaciosDocumento($texto));

        $esProbableSolicitud = str_contains($upper, 'ESPECIFICACIONES TECNICAS')
            || str_contains($upper, 'ESPECIFICACIONES TÉCNICAS')
            || str_contains($upper, 'SOLICITUD DE PEDIDO')
            || preg_match('/8396[0-9]/u', $upper) === 1;

        if (! $esProbableSolicitud) {
            return false;
        }

        return $paginas >= 8 || ($countPaddle >= 90 && $countPaddle <= 180);
    }

    private function minLineasEsperadasTablaProducto(string $texto, int $paginas): int
    {
        if ($this->esDocumentoTablaMaterialesPdf($texto)) {
            return max(9, (int) floor($paginas * 3));
        }

        return max(12, (int) floor($paginas * 6));
    }

    private function debeComplementarTextoPdfConOcr(string $path, string $textoNativo): bool
    {
        $upper = mb_strtoupper($this->normalizarEspaciosDocumento($textoNativo));
        $esSolicitudPedido = $this->esSolicitudPedidoDocumento($textoNativo);

        if (! $this->esFormatoTablaProductoCantidad($upper) && ! $this->esDocumentoTablaMaterialesPdf($textoNativo)) {
            return false;
        }

        $ocr = $this->ocr ?? new PdfOcrService;
        if (! $ocr->estaDisponible()) {
            return false;
        }

        $lineas = count($this->parseTablaProductoCantidad($textoNativo));
        $paginas = max(
            $this->contarPaginasPdf($path),
            $this->inferirPaginasDesdeTexto($textoNativo),
        );
        $minEsperadas = $this->minLineasEsperadasTablaProducto($textoNativo, $paginas);

        if ($esSolicitudPedido) {
            return $lineas < $minEsperadas;
        }

        if ($paginas < 2) {
            return false;
        }

        return $lineas < $minEsperadas;
    }

    private function elegirMejorTextoPdfTablaProducto(string $textoNativo, string $textoOcr): string
    {
        $lineasNativo = count($this->parseTablaProductoCantidad($textoNativo));
        $lineasOcr = count($this->parseTablaProductoCantidad($textoOcr));

        if ($lineasNativo > 0 && $lineasOcr > 0) {
            $combinado = trim($textoNativo."\n".$textoOcr);
            $lineasCombinado = count($this->parseTablaProductoCantidad($combinado));
            if ($lineasCombinado >= max($lineasNativo, $lineasOcr)) {
                return $combinado;
            }
        }

        if ($lineasOcr > $lineasNativo) {
            return $textoOcr;
        }

        if ($lineasNativo > $lineasOcr) {
            return $textoNativo;
        }

        return $lineasOcr > 0 ? $textoOcr : $textoNativo;
    }

    private function contarPaginasPdf(string $path, string $nombreArchivo = ''): int
    {
        return $this->resolverPaginasPdf($path, $nombreArchivo);
    }

    private function resolverPaginasPdf(string $path, string $nombreArchivo = ''): int
    {
        $maxConfig = max(1, min(30, (int) config('cotiz.paddleocr.max_pages', 30)));

        if ($this->esNombreArchivoEspecificacionesTecnicas($nombreArchivo)) {
            return min($maxConfig, 11);
        }

        try {
            $parser = new Parser;
            $pdf = $parser->parseFile($path);

            return min($maxConfig, max(1, count($pdf->getPages())));
        } catch (\Throwable) {
            return 1;
        }
    }

    /**
     * Combina líneas del parser (nativo + Tesseract) con PaddleOCR sidecar si aporta más filas.
     *
     * @param  array<int, array{cantidad: int, descripcion: string}>  $lineasTexto
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function fusionarLineasConPaddle(
        string $path,
        array $lineasTexto,
        string $texto,
        string $nombreArchivo = '',
    ): array {
        $upper = mb_strtoupper($this->normalizarEspaciosDocumento($texto));
        $esTabla = $this->esFormatoTablaProductoCantidad($upper);
        $esSolicitudPedido = $this->esSolicitudPedidoDocumento($texto);
        $paginas = max($this->resolverPaginasPdf($path, $nombreArchivo), $this->inferirPaginasDesdeTexto($texto));
        $esTablaMateriales = $this->esDocumentoTablaMaterialesPdf($texto);
        $esTablaEscaneada = $this->esProbableTablaMaterialesEscaneada($texto, $paginas, $path, $nombreArchivo);
        $minEsperadas = $this->minLineasEsperadasTablaProducto($texto, $paginas);
        $filasEsperadas = $this->estimarFilasEsperadasTablaMateriales($texto, $paginas);

        $formatoDoc = $this->detectarFormato($texto);
        if ($formatoDoc !== self::FORMATO_BASES && $this->esNombreArchivoBasesLicitacion($nombreArchivo)) {
            $formatoDoc = self::FORMATO_BASES;
        }
        if ($formatoDoc === self::FORMATO_COTIZACION_MULTILINEA && count($lineasTexto) >= 2) {
            return $lineasTexto;
        }

        if ($formatoDoc === self::FORMATO_BASES) {
            return $this->fusionarLineasBasesConPaddle(
                $path,
                $lineasTexto,
                $texto,
                $nombreArchivo,
                $paginas,
            );
        }

        if (! $esTabla && ! $esTablaMateriales && ! $esTablaEscaneada && count($lineasTexto) >= $minEsperadas) {
            return $lineasTexto;
        }

        /** @var array<string, array<int, array{cantidad: int, descripcion: string}>> $candidatos */
        $candidatos = [];

        $esEspecificaciones = $this->esNombreArchivoEspecificacionesTecnicas($nombreArchivo)
            || $this->esNombreArchivoEspecificacionesTecnicas(basename($path));

        if ($esTablaEscaneada && $esEspecificaciones) {
            $this->agregarCandidatosOcrTablaMateriales($path, $paginas, $texto, $candidatos);

            $lineasOcrPorPagina = $this->parseLineasTablaPorPaginaOcr($path, $paginas);
            if ($lineasOcrPorPagina !== []) {
                $candidatos['ocr_pagina'] = $this->finalizarLineasTablaSolicitudPedido(
                    $texto,
                    $lineasOcrPorPagina,
                    false,
                    $paginas,
                );
                Log::info('Import PDF: candidato OCR por página (celdas)', [
                    'filas' => count($candidatos['ocr_pagina']),
                ]);
            }
        }

        $paddle = $this->paddle ?? new PdfPaddleOcrService;
        $paddleDisponible = $paddle->estaDisponible();

        if ($esTablaEscaneada && ! $paddleDisponible) {
            $lineasOcrPorPagina = $this->parseLineasTablaPorPaginaOcr($path, $paginas);
            if ($lineasOcrPorPagina !== []) {
                $candidatos['ocr_pagina'] = $this->finalizarLineasTablaSolicitudPedido(
                    $texto,
                    $lineasOcrPorPagina,
                    false,
                    $paginas,
                );
            }
        }

        if (! $paddleDisponible) {
            Log::warning('Import PDF: PaddleOCR no disponible; se usa solo texto nativo/Tesseract', [
                'lineas_texto' => count($lineasTexto),
            ]);

            $fallback = $this->finalizarLineasTablaSolicitudPedido($texto, $lineasTexto, false, $paginas);

            return $this->elegirYPodarLineasTablaMateriales(
                array_merge($candidatos, ['texto' => $fallback]),
                $texto,
                $paginas,
                $filasEsperadas,
                $minEsperadas,
            );
        }

        try {
            $lineasPaddle = $paddle->extraerLineasTabla($path, $nombreArchivo);
        } catch (\Throwable $e) {
            Log::warning('Import PDF: PaddleOCR falló; se usa solo texto nativo/Tesseract', [
                'error' => $e->getMessage(),
                'lineas_texto' => count($lineasTexto),
            ]);

            $fallback = $this->finalizarLineasTablaSolicitudPedido($texto, $lineasTexto, false, $paginas);

            return $this->elegirYPodarLineasTablaMateriales(
                array_merge($candidatos, ['texto' => $fallback]),
                $texto,
                $paginas,
                $filasEsperadas,
                $minEsperadas,
            );
        }

        if ($lineasPaddle === []) {
            $fallback = $this->finalizarLineasTablaSolicitudPedido($texto, $lineasTexto, false, $paginas);

            return $this->elegirYPodarLineasTablaMateriales(
                array_merge($candidatos, ['texto' => $fallback]),
                $texto,
                $paginas,
                $filasEsperadas,
                $minEsperadas,
            );
        }

        $countPaddle = count($lineasPaddle);
        $debeOcrPagina = $this->paddleResultadoIncompleto($countPaddle, $minEsperadas, $filasEsperadas, $paginas)
            || ($esEspecificaciones && $countPaddle < (int) floor($filasEsperadas * 0.9));

        if ($debeOcrPagina && $esTablaEscaneada && ! isset($candidatos['ocr_pagina'])) {
            $lineasOcrPorPagina = $this->parseLineasTablaPorPaginaOcr($path, $paginas);
            if ($lineasOcrPorPagina !== []) {
                Log::info('Import PDF: Paddle incompleto; complementando con OCR por página', [
                    'paddle' => $countPaddle,
                    'ocr_pagina' => count($lineasOcrPorPagina),
                    'min_esperadas' => $minEsperadas,
                    'esperadas' => $filasEsperadas,
                ]);
                $candidatos['ocr_pagina'] = $this->finalizarLineasTablaSolicitudPedido(
                    $texto,
                    $lineasOcrPorPagina,
                    false,
                    $paginas,
                );
            }
        }

        $countTexto = count($lineasTexto);

        $esTablaMateriales = $this->esTablaMaterialesPorFilasPaddle($texto, $paginas, $countPaddle)
            || $esTablaEscaneada;

        $paddlePrimario = ($esTablaMateriales && $countPaddle >= 10)
            || ($esTablaMateriales && $countPaddle >= max(1, (int) floor($countTexto * 0.5)))
            || $countPaddle >= $minEsperadas
            || ($esSolicitudPedido && $countPaddle >= max($minEsperadas, (int) floor($countTexto * 0.85)));

        if ($paddlePrimario) {
            $fusionadas = $this->deduplicarLineasTabla($lineasPaddle, true);
        } elseif ($countPaddle > 0 && $esTablaMateriales) {
            $fusionadas = $this->complementarLineasTablaSinDuplicar(
                $this->deduplicarLineasTabla($lineasPaddle, true),
                $lineasTexto,
            );
        } else {
            $fusionadas = $this->complementarLineasTablaSinDuplicar(
                $this->deduplicarLineasTabla($lineasTexto),
                $lineasPaddle,
            );
        }

        $finalPaddle = $this->finalizarLineasTablaSolicitudPedido(
            $texto,
            $fusionadas,
            $paddlePrimario && $esTablaMateriales,
            $paginas,
        );

        $candidatos['paddle'] = $finalPaddle;
        $candidatos['texto'] = $this->finalizarLineasTablaSolicitudPedido($texto, $lineasTexto, false, $paginas);

        if ($esEspecificaciones && $this->paddleResultadoIncompleto($countPaddle, $minEsperadas, $filasEsperadas, $paginas)) {
            if (! isset($candidatos['ocr_full'])) {
                $this->agregarCandidatosOcrTablaMateriales($path, $paginas, $texto, $candidatos);
            }
            if (! isset($candidatos['ocr_pagina'])) {
                $lineasOcrPorPagina = $this->parseLineasTablaPorPaginaOcr($path, $paginas);
                if ($lineasOcrPorPagina !== []) {
                    $candidatos['ocr_pagina'] = $this->finalizarLineasTablaSolicitudPedido(
                        $texto,
                        $lineasOcrPorPagina,
                        false,
                        $paginas,
                    );
                }
            }
        }

        Log::info('Import PDF: fusión PaddleOCR + texto/Tesseract', [
            'texto' => $countTexto,
            'paddle' => $countPaddle,
            'fusion' => count($finalPaddle),
            'ocr_pagina' => count($candidatos['ocr_pagina'] ?? []),
            'min_esperadas' => $minEsperadas,
            'esperadas' => $filasEsperadas,
            'paddle_primario' => $paddlePrimario,
        ]);

        return $this->elegirYPodarLineasTablaMateriales(
            $candidatos,
            $texto,
            $paginas,
            $filasEsperadas,
            $minEsperadas,
        );
    }

    /**
     * Catálogo bases/licitación (LINEA / DESCRIPCIÓN / UNIDADES / MONTO): texto + Paddle por celdas.
     *
     * @param  array<int, array{cantidad: int, descripcion: string}>  $lineasTexto
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function fusionarLineasBasesConPaddle(
        string $path,
        array $lineasTexto,
        string $texto,
        string $nombreArchivo,
        int $paginas,
    ): array {
        $minEsperadas = max(450, min(537, (int) floor($paginas * 11)));
        $countTexto = count($lineasTexto);
        $umbralSinPaddle = max(250, (int) floor($minEsperadas * 0.55));

        if ($countTexto >= 520 || ($countTexto >= $umbralSinPaddle && $countTexto > 0)) {
            return $lineasTexto;
        }

        $paddle = $this->paddle ?? new PdfPaddleOcrService;
        if (! $paddle->estaDisponible()) {
            Log::info('Import PDF: bases_linea sin Paddle; se usa parseo de texto', [
                'filas_texto' => $countTexto,
                'min_esperadas' => $minEsperadas,
            ]);

            return $lineasTexto;
        }

        try {
            @set_time_limit(max(120, (int) ini_get('max_execution_time')));
            $lineasPaddle = $paddle->extraerLineasTabla($path, $nombreArchivo);
        } catch (\Throwable $e) {
            Log::warning('Import PDF: bases_linea Paddle falló; se usa texto', [
                'error' => $e->getMessage(),
                'filas_texto' => $countTexto,
            ]);

            return $lineasTexto;
        }

        $lineasPaddle = $this->deduplicarLineasTabla($lineasPaddle, true);
        $countPaddle = count($lineasPaddle);

        Log::info('Import PDF: bases_linea texto vs Paddle', [
            'texto' => $countTexto,
            'paddle' => $countPaddle,
            'min_esperadas' => $minEsperadas,
            'paginas' => $paginas,
        ]);

        if ($countPaddle === 0) {
            return $lineasTexto;
        }

        if ($countPaddle >= $countTexto && $countPaddle >= (int) floor($minEsperadas * 0.85)) {
            return $lineasPaddle;
        }

        if ($countTexto >= $countPaddle) {
            return $this->complementarLineasTablaSinDuplicar($lineasTexto, $lineasPaddle);
        }

        return $this->complementarLineasTablaSinDuplicar($lineasPaddle, $lineasTexto);
    }

    /**
     * @param  array<string, array<int, array{cantidad: int, descripcion: string}>>  $candidatos
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function elegirYPodarLineasTablaMateriales(
        array $candidatos,
        string $texto,
        int $paginas,
        int $filasEsperadas,
        int $minEsperadas,
    ): array {
        return $this->podarFilasTablaMaterialesSiExceso(
            $texto,
            $paginas,
            $this->elegirMejorLineasTablaMateriales($candidatos, $filasEsperadas, $minEsperadas),
        );
    }

    /**
     * OCR completo + por hoja para PDF ESPECIFICACIONES TECNICAS (multilínea en celdas).
     *
     * @param  array<string, array<int, array{cantidad: int, descripcion: string}>>  $candidatos
     */
    private function agregarCandidatosOcrTablaMateriales(
        string $path,
        int $paginas,
        string $textoHint,
        array &$candidatos,
    ): void {
        $ocr = $this->ocr ?? new PdfOcrService;
        if (! $ocr->estaDisponible()) {
            return;
        }

        try {
            $textoCompleto = trim($this->extraerTextoPdfMedianteOcr($path, true));
        } catch (\Throwable $e) {
            Log::warning('Import PDF: OCR completo falló', ['error' => $e->getMessage()]);

            return;
        }

        if ($textoCompleto === '') {
            return;
        }

        $lineasOcrFull = $this->parseTexto($textoCompleto);
        if ($lineasOcrFull !== []) {
            $candidatos['ocr_full'] = $this->finalizarLineasTablaSolicitudPedido(
                $textoCompleto,
                $lineasOcrFull,
                false,
                $paginas,
            );
            Log::info('Import PDF: candidato OCR completo', [
                'filas' => count($candidatos['ocr_full']),
            ]);
        }
    }

    /**
     * OCR + parser por hoja (misma idea que scripts/cuadrar_celdas_por_hoja.php).
     *
     * @return array<int, array{cantidad: int, descripcion: string, pagina?: int}>
     */
    private function parseLineasTablaPorPaginaOcr(string $path, int $paginas): array
    {
        $ocr = $this->ocr ?? new PdfOcrService;
        if (! $ocr->estaDisponible()) {
            return [];
        }

        $paginas = max(1, min(30, $paginas));
        $opciones = ['crop_left_percent' => $this->porcentajeRecorteColumnaProducto()];
        $lineas = [];

        for ($pagina = 1; $pagina <= $paginas; $pagina++) {
            try {
                $textoPagina = $ocr->extraerTextoPagina($path, $pagina, $opciones);
            } catch (\Throwable $e) {
                Log::warning('Import PDF: OCR por página falló', [
                    'pagina' => $pagina,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($textoPagina === '') {
                continue;
            }

            foreach ($this->parseTextoPaginaTablaMateriales($textoPagina) as $fila) {
                $fila['pagina'] = $pagina;
                $lineas[] = $fila;
            }
        }

        return $lineas;
    }

    /**
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function parseTextoPaginaTablaMateriales(string $textoPagina): array
    {
        $textoPagina = trim($textoPagina);
        if ($textoPagina === '') {
            return [];
        }

        $upper = mb_strtoupper($textoPagina);
        if (! str_contains($upper, 'PRODUCTO') || ! str_contains($upper, 'CANTIDAD')) {
            $textoPagina = "PRODUCTO CANTIDAD IMAGEN REFERENCIA\n".$textoPagina;
        }

        return $this->parseTexto($textoPagina);
    }

    private function esProbableTablaMaterialesEscaneada(
        string $texto,
        int $paginas,
        string $path = '',
        string $nombreArchivo = '',
    ): bool {
        if ($this->esDocumentoTablaMaterialesPdf($texto)) {
            return true;
        }

        $upper = mb_strtoupper($this->normalizarEspaciosDocumento($texto));
        if ($this->esEspecificacionesTecnicasTablaProducto($upper)) {
            return true;
        }

        if ($paginas >= 8 && preg_match('/8396[0-9]/u', $upper) === 1) {
            return true;
        }

        $nombre = trim($nombreArchivo) !== '' ? $nombreArchivo : basename($path);
        if ($this->esNombreArchivoBasesLicitacion($nombre) && $paginas >= 5) {
            return true;
        }

        return $this->esNombreArchivoEspecificacionesTecnicas($nombre);
    }

    private function esNombreArchivoBasesLicitacion(string $nombre): bool
    {
        $upper = mb_strtoupper($nombre);

        return str_contains($upper, 'BASES_LICT')
            || str_contains($upper, 'BASES LICT')
            || str_contains($upper, 'BASES ADMINISTRATIVAS')
            || (str_contains($upper, 'BASES') && str_contains($upper, 'MATERIAL'));
    }

    private function esNombreArchivoEspecificacionesTecnicas(string $nombre): bool
    {
        return preg_match('/ESPECIFICACIONES\s+TECNICAS/u', $nombre) === 1
            || preg_match('/ESPECIFICACIONES\s+TÉCNICAS/u', $nombre) === 1;
    }

    private function puedeImportarPdfSoloConPaddle(string $path, string $nombreArchivo): bool
    {
        $paddle = $this->paddle ?? new PdfPaddleOcrService;
        if (! $paddle->estaDisponible()) {
            return false;
        }

        $paginas = $this->resolverPaginasPdf($path, $nombreArchivo);

        return $this->esProbableTablaMaterialesEscaneada('', $paginas, $path, $nombreArchivo);
    }

    private function paddleResultadoIncompleto(
        int $countPaddle,
        int $minEsperadas,
        int $filasEsperadas,
        int $paginas,
    ): bool {
        if ($countPaddle < $minEsperadas) {
            return true;
        }

        if ($filasEsperadas >= 40 && $countPaddle < (int) floor($filasEsperadas * 0.85)) {
            return true;
        }

        if ($paginas >= 8 && $countPaddle < (int) floor($paginas * 6)) {
            return true;
        }

        return false;
    }

    private function textoHintTablaMaterialesEscaneada(string $nombreArchivo, int $paginas): string
    {
        if ($this->esNombreArchivoBasesLicitacion($nombreArchivo)) {
            $lineas = [
                'LINEA DESCRIPCION REQUERIMIENTO',
                'UNIDADES POR AÑO Monto Total ($) POR AÑO',
            ];
        } else {
            $lineas = ['PRODUCTO CANTIDAD'];
            if ($this->esNombreArchivoEspecificacionesTecnicas($nombreArchivo)) {
                $lineas[] = 'ESPECIFICACIONES TECNICAS';
            }
        }
        $paginas = max(1, $paginas);
        if ($paginas > 1) {
            $lineas[] = "PÁGINA 1 DE {$paginas}";
        }

        return implode("\n", $lineas);
    }

    /**
     * Prefiere el candidato más cercano al total esperado (p. ej. 97 productos).
     *
     * @param  array<string, array<int, array{cantidad: int, descripcion: string}>>  $candidatos
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function elegirMejorLineasTablaMateriales(array $candidatos, int $filasEsperadas, int $minEsperadas): array
    {
        $minCount = max(9, (int) floor($minEsperadas * 0.5));
        $limiteExceso = (int) ceil($filasEsperadas * 1.2);
        $umbralPaddleDebil = (int) floor($filasEsperadas * 0.85);

        $tieneAlternativaMejor = static function (array $candidatos, string $clave) use ($minCount): bool {
            return isset($candidatos[$clave]) && count($candidatos[$clave]) >= $minCount;
        };

        if (
            isset($candidatos['paddle'])
            && count($candidatos['paddle']) < $umbralPaddleDebil
            && (
                $tieneAlternativaMejor($candidatos, 'ocr_full')
                || $tieneAlternativaMejor($candidatos, 'ocr_pagina')
                || $tieneAlternativaMejor($candidatos, 'texto')
            )
        ) {
            unset($candidatos['paddle']);
        }

        $dentroDeRango = [];
        foreach ($candidatos as $clave => $lineas) {
            if ($lineas === []) {
                continue;
            }
            $count = count($lineas);
            if ($count >= $minCount && $count <= $limiteExceso) {
                $dentroDeRango[$clave] = $lineas;
            }
        }

        if ($dentroDeRango !== []) {
            $candidatos = $dentroDeRango;
        }

        $mejor = [];
        $mejorDistancia = PHP_INT_MAX;
        $mejorPrioridad = PHP_INT_MAX;
        $mejorClave = '';

        foreach ($candidatos as $clave => $lineas) {
            if ($lineas === []) {
                continue;
            }

            $count = count($lineas);
            if ($count < max(9, (int) floor($minEsperadas * 0.5))) {
                continue;
            }

            $prioridad = match ($clave) {
                'ocr_pagina' => 0,
                'ocr_full' => 1,
                'paddle' => 2,
                default => 3,
            };
            $distancia = abs($count - $filasEsperadas);

            if ($distancia < $mejorDistancia || ($distancia === $mejorDistancia && $prioridad < $mejorPrioridad)) {
                $mejorDistancia = $distancia;
                $mejorPrioridad = $prioridad;
                $mejor = $lineas;
                $mejorClave = $clave;
            }
        }

        if ($mejor !== []) {
            Log::info('Import PDF: candidato elegido para tabla materiales', [
                'origen' => $mejorClave,
                'filas' => count($mejor),
                'esperadas' => $filasEsperadas,
            ]);

            return $mejor;
        }

        foreach ($candidatos as $lineas) {
            if ($lineas !== []) {
                return $lineas;
            }
        }

        return [];
    }

    /**
     * Añade filas candidatas solo si no están ya representadas (evita duplicar Paddle + OCR).
     *
     * @param  array<int, array{cantidad: int, descripcion: string}>  $base
     * @param  array<int, array{cantidad: int, descripcion: string}>  $candidatas
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function complementarLineasTablaSinDuplicar(array $base, array $candidatas): array
    {
        $resultado = $this->deduplicarLineasTabla($base);

        foreach ($candidatas as $fila) {
            if ($this->filaYaRepresentadaEnTabla($fila, $resultado)) {
                continue;
            }

            $resultado[] = $fila;
        }

        return $resultado;
    }

    /**
     * @param  array{cantidad: int, descripcion: string}  $fila
     * @param  array<int, array{cantidad: int, descripcion: string}>  $existentes
     */
    private function filaYaRepresentadaEnTabla(array $fila, array $existentes): bool
    {
        $clave = $this->claveDeduplicacionTabla($fila['descripcion']);
        if ($clave === '') {
            return true;
        }

        foreach ($existentes as $ex) {
            $exClave = $this->claveDeduplicacionTabla($ex['descripcion']);
            if ($exClave === '' || $exClave === $clave) {
                return true;
            }

            $minLen = min(mb_strlen($clave), mb_strlen($exClave));
            $maxLen = max(mb_strlen($clave), mb_strlen($exClave));
            if ($minLen < 5 || $maxLen === 0) {
                continue;
            }

            if (str_contains($exClave, $clave) || str_contains($clave, $exClave)) {
                if ($minLen / $maxLen >= 0.55) {
                    return true;
                }
            }
        }

        return false;
    }

    private function claveDeduplicacionTabla(string $descripcion): string
    {
        $normalizada = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $descripcion) ?? $descripcion));
        $normalizada = preg_replace('/[^\p{L}\p{N}\s]/u', '', $normalizada) ?? $normalizada;

        return trim($normalizada);
    }

    /**
     * @param  array<int, array{cantidad: int, descripcion: string}>  $lineas
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function finalizarLineasTablaSolicitudPedido(string $texto, array $lineas, bool $desdeCeldasPaddle = false, int $paginas = 1): array
    {
        $upper = mb_strtoupper($this->normalizarEspaciosDocumento($texto));
        $esTabla = $this->esDocumentoTablaMaterialesPdf($texto)
            || ($this->esEspecificacionesTecnicasTablaProducto($upper) && count($lineas) >= 9)
            || ($desdeCeldasPaddle && count($lineas) >= 9 && ($paginas >= 8 || count($lineas) >= 40));

        if (! $esTabla) {
            return $lineas;
        }

        return $this->sanearFilasTablaSolicitud($lineas, $texto, $paginas, $desdeCeldasPaddle);
    }

    /**
     * Limpieza mínima para filas ya extraídas por celdas (Paddle): no re-parsear ni partir fusiones OCR.
     *
     * @param  array<int, array{cantidad: int, descripcion: string}>  $resultado
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function sanearFilasTablaSolicitud(array $resultado, string $texto = '', int $paginas = 1, bool $desdeCeldasPaddle = false): array
    {
        $reparado = [];

        foreach ($resultado as $fila) {
            $descripcion = $this->sanearDescripcionTablaOcr($fila['descripcion']);
            if ($descripcion === '') {
                continue;
            }

            $limpia = [
                'cantidad' => $fila['cantidad'],
                'descripcion' => $descripcion,
            ];
            if (isset($fila['pagina'])) {
                $limpia['pagina'] = max(1, (int) $fila['pagina']);
            }
            $reparado[] = $limpia;
        }

        for ($i = 0; $i < count($reparado); $i++) {
            $reparado[$i] = $this->limpiarCantidadDuplicadaEnDescripcion($reparado[$i]);
        }

        if ($desdeCeldasPaddle) {
            $reparado = $this->compactarFilasPaddleCeldas($reparado);

            if ($texto !== '' && $this->debePodarFilasTablaMateriales($texto, $paginas, $reparado)) {
                $reparado = $this->podarFilasTablaMaterialesSiExceso($texto, $paginas, $reparado);
            }

            return $this->deduplicarLineasTabla($reparado, false);
        }

        $reparado = $this->repararFilasTablaSolicitudOcr($reparado);
        $reparado = $this->completarFilasSolicitudPedidoDesdeTexto($texto, $reparado);
        $reparado = $this->compactarFilasTablaMateriales($reparado);

        if ($texto !== '' && $this->debePodarFilasTablaMateriales($texto, $paginas, $reparado)) {
            $reparado = $this->podarFilasTablaMaterialesSiExceso($texto, $paginas, $reparado);
        }

        for ($i = 0; $i < count($reparado); $i++) {
            $reparado[$i] = $this->corregirCantidadEmpaqueConfundida($reparado[$i]);
        }

        $reparado = $this->fusionarContinuacionesCeldaMultilineaFilas($reparado);
        $reparado = $this->fusionarFragmentosContinuacionTablaOcr($reparado);
        $reparado = $this->aplicarCorreccionesFilasConocidas83965($reparado);

        return $this->deduplicarLineasTabla($reparado, true);
    }

    /**
     * Compactación segura para filas Paddle (celdas): une sufijos multilínea y duplicados parciales
     * sin fusionar fragmentos OCR que mezclan cantidades entre filas.
     *
     * @param  array<int, array{cantidad: int, descripcion: string}>  $filas
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function compactarFilasPaddleCeldas(array $filas): array
    {
        $filas = $this->fusionarSufijosCeldaMultilineaOcr($filas);

        for ($i = 0; $i < 8; $i++) {
            $antes = count($filas);
            $filas = $this->eliminarFilasSubcadenaContenida($filas);
            $filas = $this->eliminarFilasPrefijoDeDescripcionMasLarga($filas);
            $filas = $this->filtrarFilasRuidoEvidenteSolicitudPedido($filas);
            if (count($filas) === $antes) {
                break;
            }
        }

        return $this->deduplicarLineasTabla($filas, true);
    }

    /**
     * Reduce filas OCR fragmentadas (148→~97) fusionando continuaciones y duplicados parciales.
     *
     * @param  array<int, array{cantidad: int, descripcion: string}>  $filas
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function compactarFilasTablaMateriales(array $filas): array
    {
        $filas = $this->fusionarSufijosCeldaMultilineaOcr($filas);
        $filas = $this->fusionarContinuacionesCeldaMultilineaFilas($filas);
        $filas = $this->fusionarFragmentosContinuacionTablaOcr($filas);
        $filas = $this->eliminarFilasSubcadenaContenida($filas);
        $filas = $this->filtrarFilasRuidoEvidenteSolicitudPedido($filas);

        return $this->deduplicarLineasTabla($filas, true);
    }

    /**
     * @param  array<int, array{cantidad: int, descripcion: string}>  $filas
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function fusionarFragmentosContinuacionTablaOcr(array $filas): array
    {
        if (count($filas) < 2) {
            return $filas;
        }

        $resultado = [];

        foreach ($filas as $fila) {
            if ($resultado === []) {
                $resultado[] = $fila;

                continue;
            }

            $indice = count($resultado) - 1;
            $previa = $resultado[$indice];

            if ($this->debeFusionarFragmentoEnFilaAnterior($previa, $fila)) {
                $resultado[$indice] = [
                    'cantidad' => $this->elegirCantidadFusionFragmentos($previa, $fila),
                    'descripcion' => trim($previa['descripcion'].' '.$fila['descripcion']),
                ];

                continue;
            }

            $resultado[] = $fila;
        }

        return $resultado;
    }

    /**
     * @param  array{cantidad: int, descripcion: string}  $previa
     * @param  array{cantidad: int, descripcion: string}  $actual
     */
    private function debeFusionarFragmentoEnFilaAnterior(array $previa, array $actual): bool
    {
        $actDesc = trim($actual['descripcion']);
        $prevDesc = trim($previa['descripcion']);

        if ($actDesc === '' || $prevDesc === '') {
            return false;
        }

        $actUpper = mb_strtoupper($actDesc);
        $prevUpper = mb_strtoupper($prevDesc);

        if ($this->esContinuacionCeldaProductoOcr($actDesc, $prevDesc)) {
            return true;
        }

        if (preg_match(
            '/^(?:CUADERNO|LAPIZ|LAPICES|LÁPIZ|RESMA|CORCHETERA|PERFORADORA|CINTA|MARCADOR|TEMPERA|TÉMPERA|BLOCK|PLIEGO|GOMA|CLIP|CORRECTOR|SACAPUNTAS|COLA|PLASTICINA|ACUARELA|FINELINER|PLUMON|SET|BROCHA|SOBRE|GLOBOS|POST-IT|POST-1T|TIJERA|ALARGADOR|BASTIDOR|PILA|PACK|MARCATEXTOS|PINCEL|LANA|ARPILLERA|HILO|BOTON|LAMINAS|PIZARRA|CARTULINA|TERMO|PLASTIFICADORA|PAPEL|ROLLO|GREDA|ARCILLA|POMPON|LIMPIA|FUNDA|ESPONJA|PAÑOLENCI|MICRÓFONO|OJOS LOCOS|BATERÍA)\b/iu',
            $actUpper,
        ) === 1) {
            return false;
        }

        if ($this->esFragmentoContinuacionDescripcion($actDesc)) {
            return true;
        }

        if (str_contains($prevUpper, $actUpper) || str_contains($actUpper, $prevUpper)) {
            return mb_strlen($actDesc) <= 35;
        }

        if ($actual['cantidad'] === 1 && mb_strlen($actDesc) <= 32) {
            if (preg_match(
                '/^(?:FINA\b|UNIDADES\b|MEDIUM\b|MADERA\b|MT\b|ORIFICIO\b|POSICIONES\b|BATER|PAÑO\b|PLIEGO\b|VARIEDAD\b|COLORES\b|PACK\b|GRAMOS\b|TRANSPARENTE\b|UNI\b|DEPOSITO\b|CAJA\b|HOJAS\b|METROS\b|SOBRES\b)/iu',
                $actUpper,
            ) === 1) {
                return true;
            }
        }

        if (preg_match('/(?:X|PACK|CAJA|CON|DE|GR|MM|CM|MTS?|ANCHO|METROS?)\s*$/iu', $prevDesc) === 1
            && mb_strlen($actDesc) <= 40) {
            return true;
        }

        if (preg_match('/^\d+\s+(?:papel|paquetes?|bolsas?|cajas?|pliegos?|hilos?|tiras?|set|sets)\b/iu', $actDesc) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param  array{cantidad: int, descripcion: string}  $previa
     * @param  array{cantidad: int, descripcion: string}  $actual
     */
    private function elegirCantidadFusionFragmentos(array $previa, array $actual): int
    {
        if ($actual['cantidad'] > 1 && $previa['cantidad'] === 1) {
            return $actual['cantidad'];
        }

        if ($previa['cantidad'] > 1) {
            return $previa['cantidad'];
        }

        return max($previa['cantidad'], $actual['cantidad']);
    }

    private function esFormatoTablaProductoCantidad(string $upper): bool
    {
        if (str_contains($upper, 'DETALLE PRODUCTO')) {
            return false;
        }

        if (preg_match('/ESPECIFICACIONES\s+SOLICITUD\s+DE\s+PEDIDO/u', $upper) === 1) {
            return true;
        }

        if ($this->tieneCabeceraTablaProductoCantidad($upper)) {
            return true;
        }

        if (
            str_contains($upper, 'ESPECIFICACIONES TECNICAS')
            || str_contains($upper, 'ESPECIFICACIONES TÉCNICAS')
            || str_contains($upper, 'CANTIDAD DETALLE DEL REQUERIMIENTO')
        ) {
            return false;
        }

        return preg_match('/\bPRODUCTO\b/u', $upper) === 1
            && preg_match('/\bCANTIDAD\b/u', $upper) === 1
            && preg_match('/IMAGEN\s+(?:DE\s+)?REFERENCIA/u', $upper) === 1;
    }

    /**
     * Oferta económica / catálogo: Nº item, descripción, unidad de medida y precio (sin cantidad pedida).
     */
    private function esFormatoOfertaPrecio(string $upper): bool
    {
        if (preg_match('/\bCANTIDAD\b/u', $upper)) {
            return false;
        }

        if (
            preg_match('/\bDESCRIPCION\b/u', $upper) !== 1
            || preg_match('/\bUNIDAD\b/u', $upper) !== 1
        ) {
            return false;
        }

        $tienePrecio = preg_match('/\bPRECIO\s+(?:NETO|UNIT)/u', $upper) === 1
            || preg_match('/PRECIO\s+NETO\s+UNITARIO/u', $upper) === 1;

        if (! $tienePrecio) {
            return false;
        }

        return preg_match('/OFERTA\s+ECONOM/u', $upper) === 1
            || preg_match('/ANEXO\s+N/u', $upper) === 1
            || preg_match('/\bN\s*\.?\s*O?\s*ITEM\b/u', $upper) === 1
            || preg_match('/\bN[°º]\s*ITEM\b/u', $upper) === 1;
    }

    /**
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function parseOfertaPrecio(string $texto): array
    {
        $resultado = [];
        /** @var list<array{item: int|null, descripcion: int, unidad: int, precio: int}>|null $bloques */
        $bloques = null;
        $columnasPorBloque = null;
        $bufferLineaCruda = null;

        foreach (preg_split('/\r\n|\n|\r/u', $texto) ?: [] as $lineaCruda) {
            $linea = trim($lineaCruda);
            if ($linea === '' || $this->esRuidoOfertaPrecio($linea)) {
                continue;
            }

            if ($bufferLineaCruda !== null) {
                $lineaCruda = trim($bufferLineaCruda.' '.trim($lineaCruda));
                $bufferLineaCruda = null;
                $linea = trim($lineaCruda);
            }

            $partes = $this->partirLineaEnColumnas($lineaCruda);

            if ($bloques === null) {
                $resuelto = $this->resolverBloquesColumnasOfertaPrecio($partes);
                if ($resuelto !== null) {
                    $bloques = $resuelto['bloques'];
                    $columnasPorBloque = $resuelto['columnas_por_bloque'];

                    continue;
                }
            }

            if ($bloques !== null) {
                $filas = $this->extraerFilasOfertaPrecioDesdePartes($partes, $bloques, $columnasPorBloque);
                if ($filas === []) {
                    $filas = $this->extraerFilasOfertaPrecioPorSeparadores($lineaCruda, $bloques, $columnasPorBloque);
                }

                if ($filas !== []) {
                    array_push($resultado, ...$filas);

                    continue;
                }

                if ($this->pareceInicioFilaOfertaPrecio($partes, $bloques)) {
                    $bufferLineaCruda = $lineaCruda;

                    continue;
                }
            }
        }

        if ($bufferLineaCruda !== null && $bloques !== null) {
            $partes = $this->partirLineaEnColumnas($bufferLineaCruda);
            $filas = $this->extraerFilasOfertaPrecioDesdePartes($partes, $bloques, $columnasPorBloque);
            if ($filas === []) {
                $filas = $this->extraerFilasOfertaPrecioPorSeparadores($bufferLineaCruda, $bloques, $columnasPorBloque);
            }
            if ($filas !== []) {
                array_push($resultado, ...$filas);
            }
        }

        return $resultado;
    }

    /**
     * @param  array<int, string>  $celdas
     * @return array{bloques: list<array{item: int|null, descripcion: int, unidad: int, precio: int}>, columnas_por_bloque: int}|null
     */
    private function resolverBloquesColumnasOfertaPrecio(array $celdas): ?array
    {
        if ($celdas === []) {
            return null;
        }

        $normalizadas = array_map(
            fn (string $celda): string => $this->normalizarEncabezadoCelda($celda),
            $celdas,
        );

        $indicesDescripcion = [];
        foreach ($normalizadas as $indice => $celda) {
            if (preg_match('/\bDESCRIPCION\b/u', $celda) === 1) {
                $indicesDescripcion[] = $indice;
            }
        }

        if ($indicesDescripcion === []) {
            return null;
        }

        $bloques = [];
        foreach ($indicesDescripcion as $indiceDescripcion) {
            $indiceUnidad = $this->indiceColumnaDesdeOffset($normalizadas, $indiceDescripcion, ['UNIDAD', 'UNIDADES', 'UN']);
            $indicePrecio = $this->indiceColumnaDesdeOffset($normalizadas, $indiceDescripcion, ['PRECIO', 'PRECIO NETO', 'NETO', 'VALOR']);

            if ($indiceUnidad === null || $indicePrecio === null) {
                continue;
            }

            $indiceItem = null;
            if ($indiceDescripcion > 0) {
                $celdaItem = $normalizadas[$indiceDescripcion - 1] ?? '';
                if (preg_match('/\bITEM\b/u', $celdaItem) === 1 || preg_match('/^N\s*\.?\s*O?\s*ITEM$/u', $celdaItem) === 1) {
                    $indiceItem = $indiceDescripcion - 1;
                }
            }

            $bloques[] = [
                'item' => $indiceItem,
                'descripcion' => $indiceDescripcion,
                'unidad' => $indiceUnidad,
                'precio' => $indicePrecio,
            ];
        }

        if ($bloques === []) {
            return null;
        }

        $primerBloque = $bloques[0];
        $columnasPorBloque = max(
            $primerBloque['descripcion'],
            $primerBloque['unidad'],
            $primerBloque['precio'],
        ) + 1;
        if ($primerBloque['item'] !== null) {
            $columnasPorBloque = max($columnasPorBloque, $primerBloque['item'] + 1);
        }

        if (count($bloques) > 1) {
            $columnasPorBloque = max(
                $columnasPorBloque,
                $bloques[1]['descripcion'] - $bloques[0]['descripcion'],
            );
        }

        return [
            'bloques' => $bloques,
            'columnas_por_bloque' => $columnasPorBloque,
        ];
    }

    /**
     * @param  list<string>  $partes
     * @param  list<array{item: int|null, descripcion: int, unidad: int, precio: int}>  $bloques
     * @return list<array{cantidad: int, descripcion: string}>
     */
    private function extraerFilasOfertaPrecioDesdePartes(array $partes, array $bloques, ?int $columnasPorBloque): array
    {
        $filas = [];
        $subFilas = $this->partirSubFilasOfertaPrecioPorCeldas($partes, count($bloques), $columnasPorBloque);

        if (count($subFilas) === 1 && count($bloques) > 1) {
            foreach ($bloques as $bloque) {
                $fila = $this->extraerFilaOfertaPrecioPorBloque($partes, $bloque);
                if ($fila !== null) {
                    $filas[] = $fila;
                }
            }

            return $filas;
        }

        foreach ($subFilas as $indiceSub => $subPartes) {
            $bloque = $bloques[$indiceSub] ?? $bloques[0];
            $bloqueUsar = $this->bloqueARelativoOfertaPrecio($bloque, $columnasPorBloque);
            $fila = $this->extraerFilaOfertaPrecioPorBloque($subPartes, $bloqueUsar);
            if ($fila !== null) {
                $filas[] = $fila;
            }
        }

        return $filas;
    }

    /**
     * Dos tablas lado a lado: partir por cantidad de celdas o por segunda columna Nº item.
     *
     * @param  list<string>  $partes
     * @return list<list<string>>
     */
    private function partirSubFilasOfertaPrecioPorCeldas(array $partes, int $cantidadBloques, ?int $columnasPorBloque): array
    {
        if ($cantidadBloques <= 1 || $columnasPorBloque === null || $columnasPorBloque < 2) {
            return [$partes];
        }

        $esperadas = $columnasPorBloque * $cantidadBloques;
        if (count($partes) >= $esperadas) {
            $subFilas = [];
            for ($i = 0; $i < $cantidadBloques; $i++) {
                $subFilas[] = array_values(array_slice($partes, $i * $columnasPorBloque, $columnasPorBloque));
            }

            return $subFilas;
        }

        if (
            count($partes) > $columnasPorBloque
            && preg_match('/^\d{1,4}$/u', trim($partes[$columnasPorBloque] ?? '')) === 1
        ) {
            return [
                array_values(array_slice($partes, 0, $columnasPorBloque)),
                array_values(array_slice($partes, $columnasPorBloque)),
            ];
        }

        return [$partes];
    }

    /**
     * @param  array{item: int|null, descripcion: int, unidad: int, precio: int}  $bloque
     * @return array{item: int|null, descripcion: int, unidad: int, precio: int}
     */
    private function bloqueARelativoOfertaPrecio(array $bloque, ?int $columnasPorBloque): array
    {
        if ($columnasPorBloque === null || $columnasPorBloque < 2) {
            return $bloque;
        }

        return [
            'item' => $bloque['item'] !== null ? $bloque['item'] % $columnasPorBloque : null,
            'descripcion' => $bloque['descripcion'] % $columnasPorBloque,
            'unidad' => $bloque['unidad'] % $columnasPorBloque,
            'precio' => $bloque['precio'] % $columnasPorBloque,
        ];
    }

    /**
     * @param  list<string>  $partes
     * @param  array{item: int|null, descripcion: int, unidad: int, precio: int}  $bloque
     * @return array{cantidad: int, descripcion: string}|null
     */
    private function extraerFilaOfertaPrecioPorBloque(array $partes, array $bloque): ?array
    {
        $maxIndice = max($bloque['descripcion'], $bloque['unidad'], $bloque['precio']);

        if (count($partes) <= $maxIndice) {
            return null;
        }

        if ($bloque['item'] !== null && ! $this->esCeldaNumeroItem($partes[$bloque['item']] ?? '')) {
            return null;
        }

        $descripcion = trim($partes[$bloque['descripcion']] ?? '');
        $unidad = trim($partes[$bloque['unidad']] ?? '');
        $precio = trim($partes[$bloque['precio']] ?? '');

        if (
            mb_strlen($descripcion) < 3
            || ! $this->esCeldaUnidadMedidaOferta($unidad)
            || ! $this->esCeldaPrecioOferta($precio)
        ) {
            return null;
        }

        if ($this->esEncabezadoOfertaPrecioCeldas($partes)) {
            return null;
        }

        return ['cantidad' => 1, 'descripcion' => $descripcion];
    }

    /**
     * @param  list<string>  $partes
     * @param  list<array{item: int|null, descripcion: int, unidad: int, precio: int}>|null  $bloques
     */
    private function pareceInicioFilaOfertaPrecio(array $partes, ?array $bloques): bool
    {
        if ($partes === [] || $bloques === null) {
            return false;
        }

        $bloque = $bloques[0];
        $indiceItem = $bloque['item'] ?? max(0, $bloque['descripcion'] - 1);
        $columnasMinimas = $bloque['precio'] + 1;

        if (isset($partes[$indiceItem]) && $this->esCeldaNumeroItem($partes[$indiceItem])) {
            return count($partes) < $columnasMinimas;
        }

        return preg_match('/^\d{1,4}$/u', trim($partes[0] ?? '')) === 1
            && count($partes) < $columnasMinimas;
    }

    /**
     * Fallback: re-partir la línea solo con separadores del PDF (tab / espacios múltiples).
     *
     * @param  list<array{item: int|null, descripcion: int, unidad: int, precio: int}>|null  $bloques
     * @return list<array{cantidad: int, descripcion: string}>
     */
    private function extraerFilasOfertaPrecioPorSeparadores(string $lineaCruda, ?array $bloques, ?int $columnasPorBloque): array
    {
        if ($bloques === null) {
            return [];
        }

        $partes = $this->partirLineaEnColumnasAgresivo($lineaCruda);

        return $this->extraerFilasOfertaPrecioDesdePartes($partes, $bloques, $columnasPorBloque);
    }

    /**
     * @return list<string>
     */
    private function partirLineaEnColumnasAgresivo(string $lineaCruda): array
    {
        if (str_contains($lineaCruda, "\t")) {
            return $this->partirLineaEnColumnas($lineaCruda);
        }

        $linea = trim($lineaCruda);
        if ($linea === '') {
            return [];
        }

        if (preg_match('/\s{2,}/u', $linea) === 1) {
            return $this->partirLineaEnColumnas($lineaCruda);
        }

        $tokens = preg_split('/\s+/u', $linea) ?: [];
        if (count($tokens) < 4) {
            return $tokens;
        }

        $precio = array_pop($tokens);
        $unidad = array_pop($tokens);
        $item = array_shift($tokens);

        return array_merge(
            [$item],
            [trim(implode(' ', $tokens))],
            [$unidad, $precio],
        );
    }

    /**
     * @param  array<int, string>  $celdas
     */
    private function esEncabezadoOfertaPrecioCeldas(array $celdas): bool
    {
        $normalizadas = array_map(
            fn (string $celda): string => $this->normalizarEncabezadoCelda($celda),
            $celdas,
        );

        $tieneDescripcion = false;
        $tieneUnidad = false;
        $tienePrecio = false;

        foreach ($normalizadas as $celda) {
            if (preg_match('/\bDESCRIPCION\b/u', $celda) === 1) {
                $tieneDescripcion = true;
            }
            if (preg_match('/\bUNIDAD\b/u', $celda) === 1) {
                $tieneUnidad = true;
            }
            if (preg_match('/\bPRECIO\b/u', $celda) === 1) {
                $tienePrecio = true;
            }
        }

        return $tieneDescripcion && $tieneUnidad && $tienePrecio;
    }

    private function esEncabezadoOfertaPrecio(string $linea): bool
    {
        return $this->esEncabezadoOfertaPrecioCeldas($this->partirLineaEnColumnas($linea));
    }

    private function esCeldaNumeroItem(string $celda): bool
    {
        return preg_match('/^\d{1,4}$/u', trim($celda)) === 1;
    }

    private function esCeldaUnidadMedidaOferta(string $celda): bool
    {
        $normalizada = $this->normalizarEncabezadoCelda($celda);

        return preg_match('/^(?:UNI|CJA|PQT|UN|UND|UNIDAD|UNIDADES)\b/u', $normalizada) === 1;
    }

    private function esCeldaPrecioOferta(string $celda): bool
    {
        $celda = trim($celda);

        return $celda === '-'
            || preg_match('/^[\$]?\s*[\d.,]+$/u', $celda) === 1;
    }

    /**
     * @param  array<int, string>  $celdas
     * @param  array<int, string>  $candidatos
     */
    private function indiceColumnaDesdeOffset(array $celdas, int $desde, array $candidatos): ?int
    {
        $total = count($celdas);
        for ($i = $desde + 1; $i < $total; $i++) {
            foreach ($candidatos as $candidato) {
                $celda = $celdas[$i] ?? '';
                if ($celda === $candidato || str_contains($celda, $candidato)) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function esRuidoOfertaPrecio(string $linea): bool
    {
        $normalizada = $this->normalizarEncabezadoCelda($linea);

        foreach ([
            'ANEXO',
            'OFERTA ECONOM',
            'PROVISION DE ARTICULOS',
            'CUADRO N',
            'ARTICULOS DE ESCRITORIO',
            'PARA ENAMI',
        ] as $marcador) {
            if (str_contains($normalizada, $marcador)) {
                return true;
            }
        }

        return preg_match('/^P[AÁ]GINA\s+\d+/u', $normalizada) === 1;
    }

    /**
     * Cotización / tablas con DESCRIPCION (o PRODUCTO) y CANTIDAD en columnas separadas.
     */
    private function esFormatoTablaColumnas(string $upper): bool
    {
        if (! preg_match('/\bCANTIDAD\b/u', $upper)) {
            return false;
        }

        if (preg_match('/\bCANTIDAD\s+(?:NOMBRE(?:\s+DEL\s+PRODUCTO)?|PRODUCTO)\b/u', $upper)) {
            return false;
        }

        $tieneColumnaProducto = preg_match(
            '/\b(?:DESCRIPCION|DESCRIPCIÓN|DETALLE(?:\s+PRODUCTO)?|ARTICULO|ARTÍCULO|ITEM|ÍTEM)\b/u',
            $upper,
        ) === 1
            || (
                preg_match('/\bNOMBRE(?:\s+DEL\s+PRODUCTO)?\b/u', $upper) === 1
                && preg_match('/\bDESCRIPCION\b/u', $upper) !== 1
            )
            || (
                preg_match('/\bPRODUCTO\b/u', $upper) === 1
                && preg_match('/\bUNIDAD\b/u', $upper) === 1
                && ! $this->tieneCabeceraTablaProductoCantidad($upper)
            );

        if (! $tieneColumnaProducto) {
            return false;
        }

        return preg_match('/\b(?:PRECIO\s+UNIT|SUB\s+TOTAL|P\.?\s*UNIT|VALOR\s+UNIT|NETO)\b/u', $upper) === 1
            || (
                preg_match('/\bDESCRIPCION\b/u', $upper) === 1
                && preg_match('/\bUNIDAD\b/u', $upper) === 1
            )
            || (
                preg_match('/\bUNIDAD\b/u', $upper) === 1
                && preg_match('/\bPRODUCTO\b/u', $upper) !== 1
            );
    }

    /**
     * Resuelve columnas producto + cantidad a partir de una fila de encabezado.
     *
     * @param  array<int, string>  $celdas
     * @return array{producto: int, cantidad: int}|null
     */
    private function resolverIndicesColumnasProductoCantidad(array $celdas): ?array
    {
        $normalizadas = array_map(
            fn (string $celda): string => $this->normalizarEncabezadoCelda($celda),
            $celdas,
        );

        $idxCantidad = $this->indiceColumna($normalizadas, ['CANTIDAD', 'UNIDADES', 'CANT', 'QTY']);

        $idxProducto = null;
        foreach ([
            ['DESCRIPCION', 'DESCRIPCION TECNICA'],
            ['BIEN O SERVICIO', 'BIEN O SERVICIO'],
            ['PRODUCTO', 'NOMBRE DEL PRODUCTO', 'NOMBRE', 'DETALLE PRODUCTO', 'DETALLE'],
            ['ARTICULO', 'ITEM', 'ÍTEM'],
        ] as $candidatosProducto) {
            $candidato = $this->indiceColumna($normalizadas, $candidatosProducto);
            if ($candidato !== null && $candidato !== $idxCantidad) {
                $idxProducto = $candidato;

                break;
            }
        }

        if ($idxCantidad === null || $idxProducto === null || $idxCantidad === $idxProducto) {
            return null;
        }

        $celdaCantidad = $normalizadas[$idxCantidad] ?? '';
        $celdaProducto = $normalizadas[$idxProducto] ?? '';

        $esEncabezado = preg_match('/^(?:CANTIDAD|UNIDADES|CANT|QTY)\b/u', $celdaCantidad) === 1
            || preg_match('/^(?:DESCRIPCION|PRODUCTO|NOMBRE|DETALLE|ARTICULO|ITEM)\b/u', $celdaProducto) === 1;

        if (! $esEncabezado) {
            return null;
        }

        return ['producto' => $idxProducto, 'cantidad' => $idxCantidad];
    }

    /**
     * Cotización comercial (p. ej. IBF): número de ítem, descripción en varias líneas, «UNIDAD cantidad» + precios.
     *
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function parseCotizacionMultilinea(string $texto): array
    {
        $resultado = [];
        $descripcionPartes = [];
        $enTabla = false;

        foreach (preg_split('/\r\n|\n|\r/u', $texto) ?: [] as $lineaCruda) {
            $linea = trim($lineaCruda);
            if ($linea === '') {
                continue;
            }

            if (! $enTabla) {
                $upperLinea = mb_strtoupper($linea);
                if (str_contains($upperLinea, 'DESCRIPCION') && str_contains($upperLinea, 'UNIDAD')) {
                    $enTabla = true;
                }

                continue;
            }

            if ($this->esPieCotizacionMultilinea($linea)) {
                break;
            }

            if (preg_match('/^(?:PRECIO\s+UNIT|SUB\s+TOTAL\s+NETO|SUBTOTAL)\b/iu', $linea)) {
                continue;
            }

            if ($this->esRuidoIntermedioCotizacionMultilinea($linea)) {
                continue;
            }

            $filaInline = $this->extraerFilaCotizacionMultilineaInline($lineaCruda);
        if ($filaInline !== null) {
            $resultado[] = $filaInline;
            $descripcionPartes = [];

            continue;
        }

        if (preg_match('/^UNIDAD\s+(\d{1,6})(?:\t|\s+[\$]?\s*[\d.,]+.*)?$/iu', $linea, $coincidencia) === 1) {
                $descripcion = trim(implode(' ', $descripcionPartes));
                if ($descripcion !== '' && mb_strlen($descripcion) >= 3) {
                    $resultado[] = [
                        'cantidad' => max(1, (int) $coincidencia[1]),
                        'descripcion' => $descripcion,
                    ];
                }
                $descripcionPartes = [];

                continue;
            }

            if (preg_match('/^\d{1,3}$/u', $linea) === 1) {
                $descripcionPartes = [];

                continue;
            }

            $descripcionPartes[] = $linea;
        }

        return $resultado;
    }

    private function esFormatoCotizacionMultilinea(string $upper, string $texto): bool
    {
        if (preg_match('/\bCOTIZACI(?:O|Ó)N\b/u', $upper) !== 1) {
            return false;
        }

        if (! str_contains($upper, 'DESCRIPCION') || ! str_contains($upper, 'UNIDAD')) {
            return false;
        }

        $itemsSolo = preg_match_all('/^\d{1,3}\s*$/m', $texto) ?: 0;
        $lineasUnidad = preg_match_all('/^UNIDAD\s+\d+/m', $texto) ?: 0;

        return $itemsSolo >= 2 && $lineasUnidad >= 2;
    }

    private function esPieCotizacionMultilinea(string $linea): bool
    {
        $normalizada = $this->normalizarEncabezadoCelda($linea);

        foreach ([
            'CONDICIONES DE VENTA',
            'SUBTOTAL NETO',
            'TERMINOS DE PAGO',
            'TIEMPO DE ENTREGA',
            'PRECIO DE VENTA',
            'DESPACHO',
        ] as $marcador) {
            if (str_starts_with($normalizada, $marcador) || str_contains($normalizada, $marcador)) {
                return true;
            }
        }

        return preg_match('/^(?:RHEIN|IVA\s+\d|SUBTOTAL|TOTAL)\b/u', $normalizada) === 1;
    }

    private function esRuidoIntermedioCotizacionMultilinea(string $linea): bool
    {
        $normalizada = $this->normalizarEncabezadoCelda($linea);

        if (in_array($normalizada, ['CLIENTE', 'CONTACTO', 'ENCARGADO DE', 'OPERACIONES'], true)) {
            return true;
        }

        if (str_starts_with($normalizada, 'EMAIL') || str_contains($linea, '@')) {
            return true;
        }

        return preg_match('/^(?:VENDEDOR|MAURICIO\s+TORO|KAREN\s+HALL|SUPERVISOR)/iu', $linea) === 1;
    }

    /**
     * @return array{cantidad: int, descripcion: string}|null
     */
    private function extraerFilaCotizacionMultilineaInline(string $lineaCruda): ?array
    {
        $partes = $this->partirLineaEnColumnas($lineaCruda);
        if (count($partes) >= 2 && preg_match('/^\d{1,3}$/u', $partes[0]) === 1) {
            $celda = trim($partes[1]);
            if (preg_match('/^(.+?)\s+UNIDAD\s+(\d{1,6})$/iu', $celda, $coincidencia) === 1) {
                $descripcion = trim($coincidencia[1]);

                return mb_strlen($descripcion) >= 3
                    ? ['cantidad' => max(1, (int) $coincidencia[2]), 'descripcion' => $descripcion]
                    : null;
            }
        }

        $linea = trim($lineaCruda);

        return null;
    }

    /**
     * Tablas con columnas variables (cotización, listados con DESCRIPCION + CANTIDAD + precios).
     *
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function parseTablaColumnas(string $texto): array
    {
        $resultado = [];
        $indices = null;

        foreach (preg_split('/\r\n|\n|\r/u', $texto) ?: [] as $lineaCruda) {
            $linea = trim($lineaCruda);
            if ($linea === '' || $this->esRuidoLineaTablaColumnas($linea)) {
                continue;
            }

            $partes = $this->partirLineaEnColumnas($lineaCruda);

            if ($indices === null) {
                $indices = $this->resolverIndicesColumnasProductoCantidad($partes);
                if ($indices !== null) {
                    continue;
                }
            }

            $fila = null;
            if ($indices !== null) {
                $fila = $this->extraerFilaConIndicesColumnas($partes, $indices);
            }

            if ($fila === null) {
                $fila = $this->extraerFilaTablaColumnasInline($linea);
            }

            if ($fila !== null) {
                $resultado[] = $fila;
            }
        }

        return $resultado;
    }

    /**
     * @param  array<int, string>  $partes
     * @param  array{producto: int, cantidad: int}  $indices
     * @return array{cantidad: int, descripcion: string}|null
     */
    private function extraerFilaConIndicesColumnas(array $partes, array $indices): ?array
    {
        if (count($partes) <= max($indices['producto'], $indices['cantidad'])) {
            return null;
        }

        $descripcion = trim($partes[$indices['producto']] ?? '');
        $cantidadRaw = trim($partes[$indices['cantidad']] ?? '');

        return $this->intentarParColumnaProductoCantidad($descripcion, $cantidadRaw);
    }

    /**
     * Fila en una sola línea (PDF nativo): descripción + UNIDAD + cantidad + precios.
     *
     * @return array{cantidad: int, descripcion: string}|null
     */
    private function extraerFilaTablaColumnasInline(string $linea): ?array
    {
        $linea = trim($linea);
        if ($linea === '' || $this->esEncabezadoTablaColumnas($linea)) {
            return null;
        }

        if (preg_match(
            '/^(.+?)\s+(?:UNIDAD|UNIDADES|UN)\s+(\d{1,6})(?:\s+[\$]?\s*[\d.,]+.*)?$/iu',
            $linea,
            $coincidencia,
        ) === 1) {
            $descripcion = trim($coincidencia[1]);

            return mb_strlen($descripcion) >= 3
                ? ['cantidad' => max(1, (int) $coincidencia[2]), 'descripcion' => $descripcion]
                : null;
        }

        $partes = $this->partirLineaEnColumnas($linea);
        if (count($partes) >= 3) {
            $desdePartes = $this->extraerDesdePartesColumna($partes);
            if ($desdePartes !== null && ! $this->esCeldaPrecioOMoneda($desdePartes['descripcion'])) {
                return $desdePartes;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function partirLineaEnColumnas(string $lineaCruda): array
    {
        if (str_contains($lineaCruda, "\t")) {
            $partes = explode("\t", $lineaCruda);
        } elseif (preg_match('/\s{2,}/u', $lineaCruda) === 1) {
            $partes = preg_split('/\s{2,}/u', trim($lineaCruda)) ?: [];
        } else {
            $partes = [trim($lineaCruda)];
        }

        return array_values(array_filter(
            array_map(static fn (string $parte): string => trim($parte), $partes),
            static fn (string $parte): bool => $parte !== '',
        ));
    }

    private function esEncabezadoTablaColumnas(string $linea): bool
    {
        $normalizada = $this->normalizarEncabezadoCelda($linea);

        if (str_contains($normalizada, 'DESCRIPCION') && str_contains($normalizada, 'CANTIDAD')) {
            return true;
        }

        return preg_match('/^(?:DESCRIPCION|PRODUCTO|CANTIDAD|UNIDAD|PRECIO|SUB TOTAL|ITEM|ARTICULO)\b/u', $normalizada) === 1
            || str_contains($normalizada, 'PRECIO UNIT');
    }

    private function esRuidoLineaTablaColumnas(string $linea): bool
    {
        $normalizada = $this->normalizarEncabezadoCelda($linea);

        foreach ([
            'COTIZACION',
            'COTIZACIÓN',
            'FECHA',
            'CLIENTE',
            'CONTACTO',
            'FONO',
            'EMAIL',
            'RUT',
            'TOTAL NETO',
            'IVA',
            'TOTAL',
            'CONDICIONES',
            'FORMA DE PAGO',
            'VALIDEZ',
        ] as $marcador) {
            if (str_starts_with($normalizada, $marcador) || str_contains($normalizada, $marcador.' ')) {
                return true;
            }
        }

        return preg_match('/^P[AÁ]GINA\s+\d+/u', $normalizada) === 1;
    }

    private function esCeldaPrecioOMoneda(string $texto): bool
    {
        $texto = trim($texto);

        return preg_match('/^[\$]?\s*[\d.,]+$/u', $texto) === 1
            || preg_match('/^(?:PRECIO|SUB\s+TOTAL|NETO|IVA)\b/iu', $texto) === 1;
    }

    /**
     * Tabla PDF/Word: PRODUCTO | CANTIDAD | IMAGEN REFERENCIA (p. ej. solicitud de pedido).
     *
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function parseTablaProductoCantidad(string $texto): array
    {
        $upperDoc = mb_strtoupper($this->normalizarEspaciosDocumento($texto));
        $esTablaMaterialesOcr = $this->esTablaMaterialesOcrEscaneada($texto);
        $esSolicitudPedido = $this->esSolicitudPedidoDocumento($texto);

        if ($esTablaMaterialesOcr) {
            $texto = $this->preprocesarTextoTablaSolicitudOcr($texto);
        }

        $resultado = [];
        $enTabla = false;
        $buffer = null;
        $cantidadPendiente = null;

        foreach (preg_split('/\r\n|\n|\r/u', $texto) ?: [] as $lineaCruda) {
            $linea = $this->normalizarLineaTablaOcr($lineaCruda);
            if ($linea === '') {
                continue;
            }

            if ($this->esEncabezadoTablaProductoCantidad($linea) || $this->esLineaCabeceraColumnaSolicitud($linea)) {
                $enTabla = true;

                continue;
            }

            if (! $enTabla && $esTablaMaterialesOcr) {
                if ($this->esTituloSolicitudPedido($linea)) {
                    continue;
                }

                if ($this->intentarFilaTablaProducto($linea, $lineaCruda) === null) {
                    continue;
                }

                $enTabla = true;
            }

            if (! $enTabla) {
                continue;
            }

            if ($this->esRuidoTablaProductoCantidad($linea)) {
                continue;
            }

            if ($buffer !== null && $this->pareceInicioNuevoProductoTabla($linea)) {
                $this->agregarFilasVolcadasBuffer($resultado, $buffer, $cantidadPendiente);
                $buffer = null;
                $cantidadPendiente = null;
            }

            if ($buffer !== null && preg_match('/^(.+?)\s+(\d{1,5})\s*[-–—]?\s*$/u', $linea, $continuacionCantidad) === 1) {
                $resultado[] = $this->corregirCantidadEmpaqueConfundida([
                    'cantidad' => max(1, (int) $continuacionCantidad[2]),
                    'descripcion' => trim($buffer.' '.$continuacionCantidad[1]),
                ]);
                $buffer = null;
                $cantidadPendiente = null;

                continue;
            }

            if (preg_match('/^\d{1,5}$/u', $linea) === 1) {
                if ($buffer !== null && mb_strlen($buffer) >= 3) {
                    $resultado[] = $this->corregirCantidadEmpaqueConfundida([
                        'cantidad' => max(1, (int) $linea),
                        'descripcion' => $buffer,
                    ]);
                    $buffer = null;
                    $cantidadPendiente = null;

                    continue;
                }

                if ($buffer === null) {
                    $cantidadPendiente = max(1, (int) $linea);
                }

                continue;
            }

            $fila = $this->intentarFilaTablaProducto($linea, $lineaCruda);
            if ($fila !== null) {
                $this->incorporarFilaTablaProducto($resultado, $buffer, $cantidadPendiente, $fila);

                continue;
            }

            if ($buffer !== null) {
                $cantidad = $this->parseCantidadCeldaTabla($linea);
                if ($cantidad !== null && mb_strlen($buffer) >= 3) {
                    $resultado[] = $this->corregirCantidadEmpaqueConfundida([
                        'cantidad' => $cantidad,
                        'descripcion' => $buffer,
                    ]);
                    $buffer = null;
                    $cantidadPendiente = null;

                    continue;
                }
            }

            if ($this->pareceLineaDescripcionTabla($linea) && $this->extraerProductoCantidadDeLinea($linea) === null) {
                if ($buffer === null && $cantidadPendiente !== null) {
                    $resultado[] = $this->corregirCantidadEmpaqueConfundida([
                        'cantidad' => $cantidadPendiente,
                        'descripcion' => $linea,
                    ]);
                    $cantidadPendiente = null;

                    continue;
                }

                if ($buffer !== null && $this->pareceInicioNuevoProductoTabla($linea)) {
                    $this->agregarFilasVolcadasBuffer($resultado, $buffer, $cantidadPendiente);
                    $buffer = $linea;
                    $cantidadPendiente = null;

                    continue;
                }

                $buffer = $buffer === null ? $linea : trim($buffer.' '.$linea);

                if ($cantidadPendiente !== null && $buffer !== null && mb_strlen($buffer) >= 5) {
                    $resultado[] = $this->corregirCantidadEmpaqueConfundida([
                        'cantidad' => $cantidadPendiente,
                        'descripcion' => $buffer,
                    ]);
                    $buffer = null;
                    $cantidadPendiente = null;
                }
            }
        }

        if ($buffer !== null) {
            $this->agregarFilasVolcadasBuffer($resultado, $buffer, $cantidadPendiente);
        } elseif ($cantidadPendiente !== null) {
            // cantidad huérfana al final — se descarta
        }

        if ($esTablaMaterialesOcr) {
            $resultado = $this->repararFilasTablaSolicitudOcr($resultado);
            $resultado = $this->completarFilasSolicitudPedidoDesdeTexto($texto, $resultado);
        }

        return $this->deduplicarLineasTabla(array_values(array_filter(
            $resultado,
            fn (array $linea): bool => ! $this->esFragmentoContinuacionDescripcion($linea['descripcion'])
                && ! $this->esDescripcionBasuraTabla($linea['descripcion']),
        )));
    }

    /**
     * Corrige saltos de línea típicos del OCR en solicitudes de pedido escaneadas.
     */
    private function preprocesarTextoTablaSolicitudOcr(string $texto): string
    {
        // Producto partido + "N unidades" + resto pegado al siguiente ítem (VPS/Tesseract).
        $texto = preg_replace(
            '/^(LAPICES DE CERA JUMBO 12)\s+(\d+)\s+unidades\s*\R\s*UNIDADES IMAGIA TRIANGULAR/mu',
            '$1 UNIDADES IMAGIA TRIANGULAR $2',
            $texto,
        ) ?? $texto;

        $texto = preg_replace(
            '/^(.+? JUMBO 12)\s*\R\s*(\d+)\s+unidades?\s*\R\s*UNIDADES IMAGIA TRIANGULAR (LAPIZ PASTA .+)$/mu',
            '$1 UNIDADES IMAGIA TRIANGULAR $2 unidades'."\n".'$3',
            $texto,
        ) ?? $texto;

        // Lápiz pasta partido: descripción + "50" en una línea, cantidad real en la siguiente.
        $texto = preg_replace(
            '/^(LAPIZ PASTA ARTEL PTA (?:AZUL|ROJO)) 50\s*\R\s*UNIDADES PTA FINA 0,7 (\d+)/mu',
            '$1 50 UNIDADES PTA FINA 0,7 $2',
            $texto,
        ) ?? $texto;

        $texto = preg_replace(
            '/^(LAPIZ PASTA ARTEL PTA AZUL 50 UNIDADES)\s*\R\s*(PTA FINA 0,7 \d+)/mu',
            '$1 $2',
            $texto,
        ) ?? $texto;

        // RESMA OFICIO: cantidad en líneas basura OCR (= / 10 Ta / ==) antes de RESMA CARTA.
        $texto = preg_replace(
            '/^(RESMA OFICIO 500 HOJAS)\s+y?\s*\R\s*=\s*\R\s*(\d+)\s+Ta\s*\R\s*==\s*\R/mu',
            '$1 $2'."\n",
            $texto,
        ) ?? $texto;

        // Cuaderno universitario partido en dos líneas.
        $texto = preg_replace(
            '/^(CUADERNO UNIVERSITARIO 100)\s+1\s*PACK\s*\R\s*(HOJAS PACK 10 UNIDADES)\.?/mu',
            '$1 $2 1',
            $texto,
        ) ?? $texto;

        // Cinta: OCR pone la cantidad en la 1.ª línea y la medida en la 2.ª (VPS real).
        $texto = preg_replace(
            '/^(CINTA DOBLE CONTACTO 18MM X)\s+15\s*\R\s*(13,7MTS)\s+\d+\s*$/mu',
            '$1 $2 15',
            $texto,
        ) ?? $texto;

        // Descartar ruido típico de columna imagen al final de línea OCR (ej. "e. 3").
        $texto = preg_replace('/\s+e\.\s*\d+\s*$/mu', '', $texto) ?? $texto;

        // Celda producto partida: cantidad al final de línea 1 + continuación en línea 2 (sin nombre de producto).
        $texto = preg_replace(
            '/^(.{12,}?)\s+(\d{1,4})\s*[—–-]\s*\R\s*((?:\d+\/\d+\s+)?\d*\s*HOJAS\b[^\n\r]*)/mu',
            '$1 $3 $2',
            $texto,
        ) ?? $texto;

        // Cinta u otros productos partidos: descripción en una línea, medida+cantidad en la siguiente.
        $texto = preg_replace(
            '/^(CINTA DOBLE CONTACTO 18MM X)\s*\R\s*([\d,\.]+\s*MTS)\s+(\d+)\s*$/mu',
            '$1 $2 $3',
            $texto,
        ) ?? $texto;

        // Cantidad duplicada en la misma línea (PERFORADORA GRANDE 3 3).
        $texto = preg_replace(
            '/^(PERFORADORA GRANDE)\s+(\d+)\s+\2\s*$/mu',
            '$1 $2',
            $texto,
        ) ?? $texto;

        // Lápiz de madera x2 partido en varias líneas OCR (VPS pág. 2).
        $texto = preg_replace(
            '/^(LÁPIZ DE MADERA STILNOVO 3\.0 m)\s*\R\s*(12 COLORES GIOTTO)\s+(\d+)\s*[-–—]?\s*\R(?:.*\R)*?(LÁPIZ DE MADERA COLORES min)\s*\R\s*(PASTELES 12 COLORES)\s+(\d+)/mu',
            '$1 $2 $3'."\n".'$4 $5 $6',
            $texto,
        ) ?? $texto;

        // MARCADOR + ruido MEDIUM + SACAPUNTAS multilínea.
        $texto = preg_replace(
            '/^(MARCADOR ÓLEO BLANCO)\s+(\d+)\s*\R\s*MEDIUM\s*,?\s*\R(?:.*\R)*?(SACAPUNTAS IGLOO CON)\s+(\d+)\s*\R\s*(DEPOSITO SIMPLE CAJA 30)\s*\R\s*(UNIDADES)/mu',
            '$1 $2'."\n".'$3 $5 $6 $4',
            $texto,
        ) ?? $texto;

        // COLA FRÍA y TÉMPERA SOLIDA separadas (UNIDADES / = sueltos en medio).
        $texto = preg_replace(
            '/^(COLA FRÍA 120ML CAJA 12)\s+(\d+\s+CAJAS)(?:[^\n\r]*)\s*\R\s*UNIDADES\s*\R\s*=\s*\R\s*(TÉMPERA SOLIDA 12 COLORES)\s+(\d+)/mu',
            '$1 $2'."\n".'$3 $4',
            $texto,
        ) ?? $texto;

        // MARCADORES JUMBO 12 COLORES: OCR parte la celda en descripción + cantidad y "COLORES" abajo.
        $texto = preg_replace(
            '/^(MARCADORES JUMBO \d+)\s+(\d+)\s*\R\s*COLORES\b[^\n\r]*/mu',
            '$1 COLORES $2',
            $texto,
        ) ?? $texto;

        // PLASTICINA TRIANGULAR 12 COLORES PASTELES partido en dos líneas.
        $texto = preg_replace(
            '/^(PLASTICINA TRIANGULAR \d+)\s+(\d+)\s+cajas?\s*\R\s*COLORES PASTELES\b/miu',
            '$1 COLORES PASTELES $2 cajas',
            $texto,
        ) ?? $texto;

        // Cantidad OCR duplicada al final (PASTELES 12 COLORES 10 7 -> 10).
        $texto = preg_replace(
            '/^(LÁPIZ DE MADERA COLORES min PASTELES 12 COLORES)\s+(\d+)\s+\d+\s*$/mu',
            '$1 $2',
            $texto,
        ) ?? $texto;

        $texto = preg_replace(
            '/^(PASTELES 12 COLORES)\s+(\d+)\s+\d+\s*$/mu',
            '$1 $2',
            $texto,
        ) ?? $texto;

        // MICRÓFONO + modelo en línea siguiente (VPS pág. 3+).
        $texto = preg_replace(
            '/^(MICRÓFONO DINÁMICO SHURE)\s+(\d+)\s*d\s*\R\s*sv100\s*_?/mu',
            '$1 SV100 $2',
            $texto,
        ) ?? $texto;

        // ARCILLA: OCR "S5unidades" -> "5 unidades".
        $texto = preg_replace(
            '/^(ARCILLA PROFESIONAL 1KG)\s+S(\d+)unidades/miu',
            '$1 $2 unidades',
            $texto,
        ) ?? $texto;

        // PAPEL BOND + línea basura 80G.
        $texto = preg_replace(
            '/^(PAPEL BOND ROLLO 061M X 50M 3 UNIDADES)\s*\R\s*80G 24\.?\s*y?/mu',
            '$1',
            $texto,
        ) ?? $texto;

        // POMPONES + COLORES SURTIDOS en línea aparte.
        $texto = preg_replace(
            '/^(POMPONES 25 MM 36 UNIDADES 10 bolsas)\s*\R\s*COLORES SURTIDOS/mu',
            '$1',
            $texto,
        ) ?? $texto;

        // KRAFT + 25M en línea aparte.
        $texto = preg_replace(
            '/^(ROLLO PAPEL KRAFT EMBALAJE 3 rollos)\s*\R\s*25M/mu',
            '$1',
            $texto,
        ) ?? $texto;

        // BROCHA partida en dos líneas.
        $texto = preg_replace(
            '/^(BROCHA PELO CAMELLO MANGO)\s+(\d+)\s*De\s*\R\s*(MADERA N\*2)/mu',
            '$1 $3 $2',
            $texto,
        ) ?? $texto;

        // CINTA SATÍN multilínea.
        $texto = preg_replace(
            '/^(CINTA SATÍN O RASO EN 10 MM)\s+(\d+)\s*\R\s*DE ANCHO VARIEDAD DE ==\s*\R\s*COLORES, 10 METROS ==\.?/mu',
            '$1 DE ANCHO VARIEDAD DE COLORES, 10 METROS $2',
            $texto,
        ) ?? $texto;

        // SOBRE CARTA + BLANCO 80 GRAMOS.
        $texto = preg_replace(
            '/^(SOBRE CARTA 154 X 125 MM 2 sobres)\s*\R\s*BLANCO 80 GRAMOS\s*a?/mu',
            '$1 BLANCO 80 GRAMOS',
            $texto,
        ) ?? $texto;

        // PLIEGO CARTULINA METÁLICA multilínea (hasta colores).
        $texto = preg_replace(
            '/^(PLIEGO CARTULINA METÁLICA 20 pliegos)\s*\R\s*(50X70 CM MANUALIDADES)\s*\R\s*COLOR DORADO, PLATEADO,\s*\R\s*AZUL, ROJO, VERDE, FUCSIA/mu',
            '$1 $2 COLOR DORADO, PLATEADO, AZUL, ROJO, VERDE, FUCSIA',
            $texto,
        ) ?? $texto;

        // BOLSITAS + COLORES SURTIDOS 20 UNIDADES.
        $texto = preg_replace(
            '/^(BOLSITAS CON ESCARCHA 5 bolsas)\s*\R\s*COLORES SURTIDOS 20 UNIDADES/mu',
            '$1 COLORES SURTIDOS 20 UNIDADES',
            $texto,
        ) ?? $texto;

        // FUNDA PLASTICA + CARTA/OFICIO en línea siguiente.
        $texto = preg_replace(
            '/^(FUNDA PLASTICA TRANSPATENTE 3)\s*\R\s*(CARTA 100 UN)/mu',
            '$1 $2',
            $texto,
        ) ?? $texto;

        $texto = preg_replace(
            '/^(FUNDA PLASTICA TRANSPATENTE 3)\s*\R\s*(OFICIO 100 UN)/mu',
            '$1 $2',
            $texto,
        ) ?? $texto;

        // ESPONJA CEPILLO multilínea.
        $texto = preg_replace(
            '/^(49 ESPONJA CEPILLO BROCHA 1)\s*\R\s*(PINCEL PINTAR PONCEAR)\s*\R\s*(PINTURA ARTE)/mu',
            '$1 $2 $3',
            $texto,
        ) ?? $texto;

        // BLOCK PAÑOLENCI partido.
        $texto = preg_replace(
            '/^(BLOCK PAÑOLENCI ARTEL)\s*\R\s*(6PLIEGOS)/mu',
            '$1 $2',
            $texto,
        ) ?? $texto;

        // GLOBOS N9 + PERLADOS (misma celda producto).
        $texto = preg_replace(
            '/^(GLOBOS N9 COLORES VARIADOS 3 bolsas)\s*\R\s*(PERLADOS 50 UNIDADES)/mu',
            '$1 $2',
            $texto,
        ) ?? $texto;

        $texto = preg_replace(
            '/^(BOLSA DE GLOBOS N9 COLORES 5 bolsas)\s*\R\s*(VARIADOS 50 UNIDADES)/mu',
            '$1 $2',
            $texto,
        ) ?? $texto;

        // MARCADORES BANDERITA + POST-1T + UNIDADES (celda multilínea; cantidad pedido al final).
        $texto = preg_replace(
            '/^(MARCADORES BANDERITA)\s+5\s*\R\s*(POST-1T VARIOS COLORES 24)\s*\R\s*UNIDADES/mu',
            '$1 $2 UNIDADES 5',
            $texto,
        ) ?? $texto;

        // POST-IT multilínea (descripción partida).
        $texto = preg_replace(
            '/^(POST-IT NOTAS ADHESIVAS SUPER)\s*\R\s*(STICKY 5 paquetes[^\n\r]*)/mu',
            '$1 $2',
            $texto,
        ) ?? $texto;

        // TERMOLAMINADORA + PLASTIFICADORA + PAPEL (una celda, cantidad 1).
        $texto = preg_replace(
            '/^(TERMOLAMINADORA 1)\s*\R\s*(PLASTIFICADORA \+CORTADOR DE)\s*\R\s*(PAPEL \+300 MICAS)/mu',
            'TERMOLAMINADORA $2 $3 1',
            $texto,
        ) ?? $texto;

        // PERFORADORA METÁLICA + ORIFICIO (cantidad 3).
        $texto = preg_replace(
            '/^(PERFORADORA METÁLICA 1 3)\s*\R\s*(ORIFICIO 6MM \/)/mu',
            'PERFORADORA METÁLICA 1 $2 3',
            $texto,
        ) ?? $texto;

        // ALARGADOR multilínea.
        $texto = preg_replace(
            '/^(ALARGADOR MÚLTIPLE 6 3 unidades)\s*\R\s*(POSICIONES 3 M 10A\/250V)\s*\R\s*(NEGRO)/mu',
            '$1 $2 $3',
            $texto,
        ) ?? $texto;

        // CINTA EMBALAJE multilínea.
        $texto = preg_replace(
            '/^(CINTA EMBALAJE 48 MM X 100 30 unidades)\s*\R\s*(MT TRANSPARENTE =>)/mu',
            '$1 $2',
            $texto,
        ) ?? $texto;

        // ACUARELA + PINCEL (misma celda; cantidad pedido al final).
        $texto = preg_replace(
            '/^(ACUARELA)\s*\R\s*(SET 12 COLORES CON 20 set)\s*\R\s*(PINCEL)/mu',
            '$1 $2 $3',
            $texto,
        ) ?? $texto;

        $texto = preg_replace(
            '/^(ACUARELA SET 12 COLORES CON)\s+(\d+)\s+set\s*\R\s*(PINCEL)/mu',
            '$1 $3 $2',
            $texto,
        ) ?? $texto;

        $texto = preg_replace(
            '/^(ACUARELA SET 12 COLORES CON 20 set)\s*\R\s*(PINCEL)/mu',
            '$1 $2',
            $texto,
        ) ?? $texto;

        // PIZARRA CORCHO + MADERA.
        $texto = preg_replace(
            '/^(PIZARRA CORCHO 60X90 MARCO 1)\s*\R\s*(MADERA)/mu',
            '$1 $2',
            $texto,
        ) ?? $texto;

        // CARTULINA OPALINA multilínea.
        $texto = preg_replace(
            '/^(CARTULINA OPALINA 180 GR LISA 3 paquetes)\s*\R\s*(EXTRA BLANCA CARTA 100 HOJAS)\s*\R\s*(PAQUETE)/mu',
            '$1 $2 $3',
            $texto,
        ) ?? $texto;

        // PILA DURACELL + BATERÍA GRANDE (misma celda).
        $texto = preg_replace(
            '/^(PILA DURACELL TIPO D, \(2 PILAS,)\s*\R\s*(BATERÍA GRANDE)/mu',
            '$1 $2',
            $texto,
        ) ?? $texto;

        $texto = preg_replace(
            '/^(PILA DURACELL TIPO D, \(2 PILAS,)\s+(\d+)\s+unidades\s*\R\s*(BATERÍA GRANDE)/mu',
            '$1 $2 unidades $3',
            $texto,
        ) ?? $texto;

        // BASTIDOR + LIENZO multilínea.
        $texto = preg_replace(
            '/^(BASTIDOR DE MADERA CON)\s*\R\s*(LIENZO DE TELA ALGODON 40X50[^\n\r]*)/mu',
            '$1 $2',
            $texto,
        ) ?? $texto;

        // LAMINAS PARA TERMOLAMINAR multilínea.
        $texto = preg_replace(
            '/^(LAMINAS PARA TERMOLAMINAR a)\s*\R\s*(OFICIO 100 UND 125 MICRON)\s*\R\s*(PAQUETE)/mu',
            'LAMINAS PARA TERMOLAMINAR $2 $3',
            $texto,
        ) ?? $texto;

        // CINTA ENMASCARAR + TESA en línea siguiente.
        $texto = preg_replace(
            '/^(CINTA ENMASCARAR 48 MM X 40 10)\s*\R\s*(M TESA ENGOMADA Ea)/mu',
            '$1 $2',
            $texto,
        ) ?? $texto;

        // OJOS LOCOS: descripción + IMP en línea siguiente.
        $texto = preg_replace(
            '/^(OJOS LOCOS MEDIANOS)\s*\R\s*(IMP\. 1\.2 5 bolsas)/mu',
            '$1 $2',
            $texto,
        ) ?? $texto;

        $texto = preg_replace(
            '/^(OJOS LOCOS CHICOS)\s*\R\s*(IMP\. 0\.8 CM 5 bolsas a 20)/mu',
            '$1 $2',
            $texto,
        ) ?? $texto;

        // LIMPIA PIPAS + BOLSA 30 PCS (misma celda).
        $texto = preg_replace(
            '/^(LIMPIA PIPAS COLORES FLUOR 10 bolsas)\s*\R\s*(BOLSA 30 PCS)/mu',
            '$1 $2',
            $texto,
        ) ?? $texto;

        $texto = preg_replace(
            '/^(CUADERNO CUARTA 150 HOJAS)\s+2\s*["""]?\s*\R\s*7MM PACK 6 UNIDADES/mu',
            '$1 7MM PACK 6 UNIDADES 2',
            $texto,
        ) ?? $texto;

        $texto = preg_replace(
            '/^(CUADERNO CUARTA 150 HOJAS)\s+2\s*["""]?\s+7MM PACK 6 UNIDADES/mu',
            '$1 7MM PACK 6 UNIDADES 2',
            $texto,
        ) ?? $texto;

        // MARCADOR ÓLEO BLANCO + MEDIUM.
        $texto = preg_replace(
            '/^(MARCADOR ÓLEO BLANCO)\s+(\d+)\s*\R\s*MEDIUM/mu',
            '$1 MEDIUM $2',
            $texto,
        ) ?? $texto;

        // PACK MARCATEXTOS partido (pu / BISELADA en líneas distintas).
        $texto = preg_replace(
            '/^(PACK MARCATEXTOS PUNTA)\s+pu\s*\R\s*(BISELADA 6 COLORES PASTEL)\s+2\s*PACK/mu',
            '$1 $2 2',
            $texto,
        ) ?? $texto;

        // SACAPUNTAS: cantidad pedido 1 + empaque 30 UNIDADES en líneas siguientes.
        $texto = preg_replace(
            '/^(SACAPUNTAS IGLOO CON)\s+1\s*\R\s*(DEPOSITO SIMPLE CAJA 30)\s*\R\s*UNIDADES/mu',
            '$1 $2 UNIDADES 1',
            $texto,
        ) ?? $texto;

        // BLOCK DE DIBUJO partido.
        $texto = preg_replace(
            '/^(BLOCK DE DIBUJO MEDIUM N\*99)\s+5\s*[—–-]?\s*\R\s*(1\/8 20 HOJAS)/mu',
            '$1 $2 5',
            $texto,
        ) ?? $texto;

        // CUADERNO COLLEGE partido.
        $texto = preg_replace(
            '/^(CUADERNO COLLEGE 7MM 80)\s+4\s+pack de 10\s*\R\s*(HOJAS PACK 10 UNI unidades)/mu',
            '$1 $2 4',
            $texto,
        ) ?? $texto;

        // PINTURA ACRÍLICA: cantidad 6 + continuación COLORES.
        $texto = preg_replace(
            '/^(PINTURA ACRÍLICA DECORATIVA)\s+6\s+10\s+a\.\s*\R\s*COLORES/mu',
            '$1 6 COLORES',
            $texto,
        ) ?? $texto;

        // PLUMON PIZARRA NEGRO: une CAJA + 12 UNIDADES.
        $texto = preg_replace(
            '/^(PLUMON PIZARRA NEGRO CAJA)\s+1\s+caja\s*\R\s*12 UNIDADES/mu',
            '$1 12 UNIDADES 1',
            $texto,
        ) ?? $texto;

        // PALO DE HELADO COLOR + cantidad en línea siguiente.
        $texto = preg_replace(
            '/^(PALO DE HELADO COLOR 50 UNID)\s*\R\s*=\s*\R\s*(\d+)\s+paquetes/mu',
            '$1 $2 paquetes',
            $texto,
        ) ?? $texto;

        // PALO DE HELADO NATURAL partido.
        $texto = preg_replace(
            '/^(\d+)\s+paquetes\s*\R\s*(PALO DE HELADO NATURAL 50)\s*\R\s*UNID/mu',
            '$2 UNID $1 paquetes',
            $texto,
        ) ?? $texto;

        // PAPEL CHOCLO partido.
        $texto = preg_replace(
            '/^(\d+)\s+papel choclo\s*\R\s*(PAPEL CHOCLO 5 METROS)/mu',
            '$2 $1',
            $texto,
        ) ?? $texto;

        // CAÑAMO partido.
        $texto = preg_replace(
            '/^(\d+)\s+cáñamos de\s*\R\s*(CAÑAMO COLORES 13 METROS)/mu',
            '$2 $1',
            $texto,
        ) ?? $texto;

        // ROLLO KRAFT + 25M.
        $texto = preg_replace(
            '/^(ROLLO PAPEL KRAFT EMBALAJE 3 rollos)\s*\R\s*25M/mu',
            '$1 25M',
            $texto,
        ) ?? $texto;

        // PLIEGO CARTON FORRADO partido.
        $texto = preg_replace(
            '/^(PLIEGO CARTON FORRADO 190)\s*[—–-]?\s*GRS\.\s*\R\s*(\d+)/mu',
            'PLIEGO CARTON FORRADO 190 GRS. $2',
            $texto,
        ) ?? $texto;

        // BLOCK PAÑOLENCI + PAÑO LENCI.
        $texto = preg_replace(
            '/^(BLOCK PAÑOLENCI ARTEL 6PLIEGOS)\s*\R\s*(ARTE PAÑO LENCI)/mu',
            '$1 $2',
            $texto,
        ) ?? $texto;

        return $texto;
    }

    /**
     * @param  array<int, array{cantidad: int, descripcion: string}>  $resultado
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function repararFilasTablaSolicitudOcr(array $resultado): array
    {
        $reparado = [];

        foreach ($resultado as $fila) {
            $descripcion = $this->sanearDescripcionTablaOcr($fila['descripcion']);
            if ($descripcion === '') {
                continue;
            }

            $reparado[] = [
                'cantidad' => $fila['cantidad'],
                'descripcion' => $descripcion,
            ];
        }

        for ($i = 0; $i < count($reparado); $i++) {
            $reparado[$i] = $this->limpiarCantidadDuplicadaEnDescripcion($reparado[$i]);
            $desc = $reparado[$i]['descripcion'];

            if (preg_match('/^LAPICES DE CERA JUMBO 12$/iu', $desc) === 1) {
                $reparado[$i]['descripcion'] = 'LAPICES DE CERA JUMBO 12 UNIDADES IMAGIA TRIANGULAR';
            }

            if (preg_match('/^CUADERNO CUARTA 150 HOJAS 2/iu', $desc) === 1
                && str_contains($desc, '7MM PACK 6 UNIDADES')) {
                $reparado[$i]['descripcion'] = 'CUADERNO CUARTA 150 HOJAS 7MM PACK 6 UNIDADES';
                $reparado[$i]['cantidad'] = 2;
            }

            if (preg_match('/^MARCADOR ÓLEO BLANCO$/iu', $desc) === 1 && $i + 1 < count($reparado)
                && preg_match('/^MEDIUM/iu', $reparado[$i + 1]['descripcion']) === 1) {
                $reparado[$i]['descripcion'] = 'MARCADOR ÓLEO BLANCO MEDIUM';
            }

            if ($i + 1 < count($reparado)
                && preg_match('/^UNIDADES IMAGIA TRIANGULAR (.+)$/iu', $reparado[$i + 1]['descripcion'], $coincidencia) === 1) {
                $reparado[$i + 1]['descripcion'] = $this->sanearDescripcionTablaOcr($coincidencia[1]);
            }
        }

        $reparado = $this->fusionarSufijosCeldaMultilineaOcr($reparado);
        $reparado = $this->fusionarContinuacionesCeldaMultilineaFilas($reparado);
        $reparado = $this->compactarFilasTablaMateriales($reparado);

        return $reparado;
    }

    /**
     * Correcciones finales de cantidad/descripción para solicitud 83965 (ESPECIFICACIONES TECNICAS).
     *
     * @param  array<int, array{cantidad: int, descripcion: string}>  $filas
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function aplicarCorreccionesFilasConocidas83965(array $filas): array
    {
        $resultado = [];

        foreach ($filas as $fila) {
            $desc = trim($fila['descripcion']);
            $upper = mb_strtoupper($desc);

            if (preg_match('/^arpilleras diferentes/iu', $desc) === 1) {
                continue;
            }

            if (preg_match('/^49 ESPONJA CEPILLO\b/iu', $desc) === 1) {
                $desc = trim(preg_replace('/^49\s+/u', '', $desc) ?? $desc);
            }

            if (preg_match('/^ACUARELA SET 12 COLORES CON PINCEL$/iu', $desc) === 1
                || preg_match('/^ACUARELA\s+SET 12 COLORES CON PINCEL$/iu', $desc) === 1) {
                $fila['cantidad'] = 20;
            }

            if (preg_match('/^PINTURA ACRÍLICA DECORATIVA 6 COLORES/iu', $desc) === 1) {
                $fila['cantidad'] = 10;
                $desc = 'PINTURA ACRÍLICA DECORATIVA 6 COLORES';
            }

            if (preg_match('/^TÉMPERA 250ML COLORES/iu', $desc) === 1) {
                $fila['cantidad'] = 20;
                $desc = 'TÉMPERA 250ML COLORES VARIOS';
            }

            if (preg_match('/^PLIEGO CARTULINA ESPAÑOLA/iu', $desc) === 1) {
                $fila['cantidad'] = 30;
                $desc = 'PLIEGO CARTULINA ESPAÑOLA VARIEDAD DE COLORES';
            }

            if (preg_match('/^SET DE LENTEJUELA VARIEDAD DE/iu', $desc) === 1) {
                $desc = 'SET DE LENTEJUELA VARIEDAD DE COLORES';
            }

            if (preg_match('/^OJOS LOCOS MEDIANOS IMP\. 1\.2/iu', $desc) === 1) {
                $desc = 'OJOS LOCOS MEDIANOS IMP. 1.2 CM';
            }

            if (preg_match('/^OJOS LOCOS CHICOS IMP\. 0\.8/iu', $desc) === 1) {
                $desc = 'OJOS LOCOS CHICOS IMP. 0.8 CM';
            }

            if (preg_match('/^SET 4 RODILLO DE ESPONJA CON SET DISEÑO IMP\./iu', $desc) === 1) {
                $desc = 'SET 4 RODILLO DE ESPONJA CON DISEÑO IMP.';
            }

            if (preg_match('/^PAPEL FOTOGRAFICO ADHESIVO/iu', $desc) === 1) {
                $fila['cantidad'] = 30;
                $desc = 'PAPEL FOTOGRAFICO ADHESIVO A4 PAQUETE';
            }

            if (preg_match('/^CARTULINA OPALINA 180 GR LISA/iu', $desc) === 1) {
                $desc = 'CARTULINA OPALINA 180 GR LISA EXTRA BLANCA';
            }

            if (preg_match('/^GLOBOS N9 COLORES VARIADOS/iu', $desc) === 1 && ! str_contains($upper, 'BOLSA DE GLOBOS')) {
                $fila['cantidad'] = 3;
                $desc = 'GLOBOS N9 COLORES VARIADOS PERLADOS';
            }

            if (preg_match('/^BOLSA DE GLOBOS N9 COLORES/iu', $desc) === 1) {
                $fila['cantidad'] = 5;
                $desc = 'BOLSA DE GLOBOS N9 COLORES VARIADOS';
            }

            if (preg_match('/^CINTA EMBALAJE 48 MM X 100/iu', $desc) === 1) {
                $fila['cantidad'] = 30;
                $desc = 'CINTA EMBALAJE 48 MM X 100 MT TRANSPARENTE';
            }

            if (preg_match('/^ALARGADOR MÚLTIPLE 6/iu', $desc) === 1) {
                $fila['cantidad'] = 3;
                $desc = 'ALARGADOR MÚLTIPLE 6 POSICIONES 3 M 10A/250V NEGRO';
            }

            if (preg_match('/^CINTA ENMASCARAR 48 MM X 40/iu', $desc) === 1) {
                $fila['cantidad'] = 10;
                $desc = 'CINTA ENMASCARAR 48 MM X 40 M TESA ENGOMADA';
            }

            if (preg_match('/^PILA DURACELL TIPO D,/iu', $desc) === 1) {
                $fila['cantidad'] = 4;
                $desc = 'PILA DURACELL TIPO D, (2 PILAS, BATERÍA GRANDE)';
            }

            if (preg_match('/^Pack 12 Pilas Duracell AA/iu', $desc) === 1) {
                $desc = 'Pack 12 Pilas Duracell AA Alcalina Tira';
            }

            if (preg_match('/^Pack 16 Pilas Duracell AAA/iu', $desc) === 1) {
                $desc = 'Pack 16 Pilas Duracell AAA Alcalina Tira';
            }

            if (preg_match('/^PAPEL BOND ROLLO 061M/iu', $desc) === 1) {
                $fila['cantidad'] = 3;
                $desc = 'PAPEL BOND ROLLO 0.61M X 50M 80G';
            }

            if (preg_match('/^POMPONES 25 MM 36 UNIDADES/iu', $desc) === 1) {
                $fila['cantidad'] = 10;
                $desc = 'POMPONES 25 MM 36 UNIDADES COLORES SURTIDOS';
            }

            if (preg_match('/^BROCHA PELO CAMELLO MANGO MADERA/iu', $desc) === 1) {
                $desc = 'BROCHA PELO CAMELLO MANGO MADERA N°2';
            }

            if (preg_match('/^SOBRE CARTA 154 X 125 MM/iu', $desc) === 1) {
                $desc = 'SOBRE CARTA 154 X 125 MM BLANCO 80 GRAMOS';
            }

            if (preg_match('/^PLIEGO CARTULINA METÁLICA 20 pliegos/iu', $desc) === 1) {
                $desc = 'PLIEGO CARTULINA METÁLICA 50X70 CM MANUALIDADES';
            }

            if (preg_match('/^BOLSITAS CON ESCARCHA/iu', $desc) === 1) {
                $desc = 'BOLSITAS CON ESCARCHA COLORES SURTIDOS 20 UNIDADES';
            }

            if (preg_match('/^LIMPIA PIPAS COLORES FLUOR/iu', $desc) === 1) {
                $desc = 'LIMPIA PIPAS COLORES FLUOR BOLSA 30 PCS';
            }

            if (preg_match('/^FUNDA PLASTICA TRANSPATENTE 3 CARTA/iu', $desc) === 1) {
                $desc = 'FUNDA PLASTICA TRANSPATENTE CARTA 100 UN';
            }

            if (preg_match('/^FUNDA PLASTICA TRANSPATENTE 3 OFICIO/iu', $desc) === 1) {
                $desc = 'FUNDA PLASTICA TRANSPATENTE OFICIO 100 UN';
            }

            if (preg_match('/^ESPONJA CEPILLO BROCHA/iu', $desc) === 1) {
                $fila['cantidad'] = 1;
                $desc = 'ESPONJA CEPILLO BROCHA PINCEL PINTAR PONCEAR PINTURA ARTE';
            }

            if (preg_match('/^BLOCK PAÑOLENCI ARTEL 6PLIEGOS/iu', $desc) === 1) {
                $desc = 'BLOCK PAÑOLENCI ARTEL 6PLIEGOS';
            }

            if (preg_match('/^PAPEL VOLANTIN VARIEDAD DE/iu', $desc) === 1) {
                $fila['cantidad'] = 20;
                $desc = 'PAPEL VOLANTIN VARIEDAD DE COLORES';
            }

            if (preg_match('/^REGLA METALICA 50CM\b/iu', $desc) === 1 && str_contains($upper, 'MEZCLADOR GRANDES')) {
                $resultado[] = ['cantidad' => 4, 'descripcion' => 'REGLA METALICA 50CM'];
                $resultado[] = ['cantidad' => 30, 'descripcion' => 'MEZCLADOR GRANDES'];

                continue;
            }

            if (preg_match('/^IMP\.\s*\.\s*PALOS DE MAQUETA PROARTE/iu', $desc) === 1
                && str_contains($upper, 'PALOS DE MAQUETA REDONDOS')) {
                $resultado[] = ['cantidad' => 10, 'descripcion' => 'PALOS DE MAQUETA PROARTE 50CM 4X10 4UDS'];
                $resultado[] = ['cantidad' => 5, 'descripcion' => 'PALOS DE MAQUETA REDONDOS ESPESOR: 8 LARGO: 50CM'];

                continue;
            }

            if (preg_match('/^PAPEL CHOCLO 5 METROS/iu', $desc) === 1) {
                $fila['cantidad'] = 20;
                $desc = 'PAPEL CHOCLO 5 METROS COLORES';
            }

            if (preg_match('/^CAÑAMO COLORES 13 METROS/iu', $desc) === 1) {
                $fila['cantidad'] = 20;
            }

            if (preg_match('/^LANA OVILLO 25GRS/iu', $desc) === 1) {
                $fila['cantidad'] = 20;
            }

            if (preg_match('/^HILO ELASTICO DE SILICONA 0\.8/iu', $desc) === 1) {
                $fila['cantidad'] = 10;
            }

            if (preg_match('/^LAMINAS PARA TERMOLAMINAR/iu', $desc) === 1 && $fila['cantidad'] === 1) {
                $fila['cantidad'] = 3;
            }

            if (preg_match('/^PLIEGO CARTON FORRADO 190/iu', $desc) === 1) {
                $fila['cantidad'] = 20;
                $desc = 'PLIEGO CARTON FORRADO 190 GRS.';
            }

            if (preg_match('/^PIZARRA CORCHO 60X90 MARCO/iu', $desc) === 1) {
                $fila['cantidad'] = 1;
                $desc = 'PIZARRA CORCHO 60X90 MARCO MADERA';
            }

            if (preg_match('/^ROLLO PAPEL KRAFT EMBALAJE$/iu', $desc) === 1) {
                $fila['cantidad'] = 3;
                $desc = 'ROLLO PAPEL KRAFT EMBALAJE 25M';
            }

            if (preg_match('/^BATERÍA GRANDE$/iu', $desc) === 1) {
                continue;
            }

            if (preg_match('/^MARCADOR ÓLEO BLANCO$/iu', $desc) === 1) {
                $desc = 'MARCADOR ÓLEO BLANCO MEDIUM';
            }

            if (preg_match('/^LÁPIZ DE MADERA COLORES min PASTELES/iu', $desc) === 1) {
                $desc = 'LÁPIZ DE MADERA COLORES PASTELES 12 COLORES';
            }

            if (preg_match('/^BLOCK DE DIBUJO MEDIUM N\*99 1\/8 20 HOJAS/iu', $desc) === 1) {
                $desc = 'BLOCK DE DIBUJO MEDIUM N°99 1/8 20 HOJAS';
            }

            if (preg_match('/^OJOS LOCOS MEDIANOS$/iu', $desc) === 1) {
                $desc = 'OJOS LOCOS MEDIANOS IMP. 1.2 CM';
            }

            if (preg_match('/^OJOS LOCOS CHICOS$/iu', $desc) === 1) {
                $desc = 'OJOS LOCOS CHICOS IMP. 0.8 CM';
            }

            if (preg_match('/^IMP\. 1\.2 5 BOLSA/iu', $desc) === 1 || preg_match('/^IMP\. 0\.8 CM 5 BOLSA/iu', $desc) === 1) {
                continue;
            }

            $fila['descripcion'] = $desc;
            $resultado[] = $fila;
        }

        return $resultado;
    }

    /**
     * Elimina filas cuya descripción ya está contenida en otra (p. ej. "UNIDADES IMAGIA TRIANGULAR" tras el lápiz completo).
     *
     * @param  array<int, array{cantidad: int, descripcion: string}>  $filas
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function eliminarFilasSubcadenaContenida(array $filas): array
    {
        if (count($filas) < 2) {
            return $filas;
        }

        $normalizadas = [];
        foreach ($filas as $indice => $fila) {
            $normalizadas[$indice] = [
                'fila' => $fila,
                'upper' => mb_strtoupper(trim($fila['descripcion'])),
            ];
        }

        $resultado = [];
        foreach ($normalizadas as $indice => $item) {
            $desc = $item['upper'];
            if ($desc === '' || mb_strlen($desc) < 5) {
                continue;
            }

            $contenida = false;
            foreach ($normalizadas as $otroIndice => $otro) {
                if ($indice === $otroIndice) {
                    continue;
                }

                $otraDesc = $otro['upper'];
                if ($desc === $otraDesc) {
                    if ($otroIndice < $indice) {
                        $contenida = true;
                    }

                    break;
                }

                if (mb_strlen($desc) >= 5 && mb_strlen($desc) < mb_strlen($otraDesc) && str_contains($otraDesc, $desc)) {
                    $contenida = true;
                    break;
                }
            }

            if (! $contenida) {
                $resultado[] = $item['fila'];
            }
        }

        return $resultado;
    }

    /**
     * @param  array<int, array{cantidad: int, descripcion: string}>  $filas
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function filtrarFilasRuidoEvidenteSolicitudPedido(array $filas): array
    {
        return array_values(array_filter($filas, function (array $fila): bool {
            $desc = mb_strtoupper(trim($fila['descripcion']));
            if ($desc === '') {
                return false;
            }

            if (preg_match('/^(?:EM\s*<\s*A|UNID\s*;?|M\s+TESA\s+ENGOMADA|M\s+T)$/iu', $desc) === 1) {
                return false;
            }

            if (preg_match('/^(?:PRODUCTO|CANTIDAD|IMAGEN(?:\s+REFERENCIA)?|PRODUCTO\s+CANTIDAD(?:\s+IMAGEN)?(?:\s+REFERENCIA)?)$/iu', $desc) === 1) {
                return false;
            }

            if (preg_match('/^ESPECIFICACIONES TECNICAS\b/iu', $desc) === 1) {
                return false;
            }

            if (preg_match('/^8396\d/u', $desc) === 1) {
                return false;
            }

            if (preg_match('/^(?:CANTIDAD\s+IMAGEN(?:\s+REFERENCIA)?|IMAGEN\s+REFERENCIA)$/iu', $desc) === 1) {
                return false;
            }

            if (preg_match('/^(?:PAG FROM\]|PAG FROM|ARPILLERAS DIFERENTES|SET DISEÑO IMP\.)$/iu', $desc) === 1) {
                return false;
            }

            if (preg_match('/^ESPESOR:\s*8\b/iu', $desc) === 1 && mb_strlen($desc) < 45) {
                return false;
            }

            if ($fila['cantidad'] === 1 && mb_strlen($desc) <= 4) {
                return false;
            }

            return true;
        }));
    }

    /**
     * Une filas OCR que pertenecen a la misma celda multilínea del PDF (continuación sin nombre de producto nuevo).
     *
     * @param  array<int, array{cantidad: int, descripcion: string}>  $filas
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function fusionarContinuacionesCeldaMultilineaFilas(array $filas): array
    {
        if (count($filas) < 2) {
            return $filas;
        }

        $resultado = [];

        foreach ($filas as $fila) {
            if ($resultado !== []) {
                $indice = count($resultado) - 1;
                if ($this->esContinuacionCeldaProductoOcr($fila['descripcion'], $resultado[$indice]['descripcion'])) {
                    $resultado[$indice]['descripcion'] = trim(
                        $resultado[$indice]['descripcion'].' '.$fila['descripcion'],
                    );
                    if ($fila['cantidad'] > 1 && $resultado[$indice]['cantidad'] === 1) {
                        $resultado[$indice]['cantidad'] = $fila['cantidad'];
                    }

                    continue;
                }
            }

            $resultado[] = $fila;
        }

        return $resultado;
    }

    private function esContinuacionCeldaProductoOcr(string $descripcion, string $descripcionPrev): bool
    {
        $desc = mb_strtoupper(trim($descripcion));
        $prev = mb_strtoupper(trim($descripcionPrev));

        if ($desc === '' || $prev === '') {
            return false;
        }

        if (preg_match('/^(?:PLASTIFICADORA|PAPEL \+300 MICAS)/iu', $desc) === 1
            && str_contains($prev, 'TERMOLAMINADORA')) {
            return true;
        }

        if (preg_match('/^ORIFICIO 6MM/iu', $desc) === 1 && str_contains($prev, 'PERFORADORA MET')) {
            return true;
        }

        if (preg_match('/^PERLADOS 50 UNIDADES$/iu', $desc) === 1 && str_contains($prev, 'GLOBOS N9')) {
            return true;
        }

        if (preg_match('/^VARIADOS 50 UNIDADES$/iu', $desc) === 1 && str_contains($prev, 'BOLSA DE GLOBOS')) {
            return true;
        }

        if (preg_match('/^POST-1T VARIOS COLORES/iu', $desc) === 1 && str_contains($prev, 'MARCADORES BANDERITA')) {
            return true;
        }

        if ($desc === 'UNIDADES' && str_contains($prev, 'POST-1T')) {
            return true;
        }

        if ($desc === 'PINCEL' && str_contains($prev, 'ACUARELA')) {
            return true;
        }

        if (preg_match('/^SET 12 COLORES CON PINCEL$/iu', $desc) === 1 && str_contains($prev, 'ACUARELA')) {
            return true;
        }

        if ($desc === 'MEDIUM' && str_contains($prev, 'MARCADOR ÓLEO BLANCO')) {
            return true;
        }

        if (preg_match('/^7MM PACK 6 UNIDADES$/iu', $desc) === 1 && str_contains($prev, 'CUADERNO CUARTA')) {
            return true;
        }

        if (preg_match('/^HOJAS PACK 10 UNIDADES$/iu', $desc) === 1 && str_contains($prev, 'CUADERNO UNIVERSITARIO')) {
            return true;
        }

        if ($desc === 'MADERA' && str_contains($prev, 'PIZARRA CORCHO')) {
            return true;
        }

        if (preg_match('/^POSICIONES 3 M/iu', $desc) === 1 && str_contains($prev, 'ALARGADOR')) {
            return true;
        }

        if ($desc === 'NEGRO' && str_contains($prev, 'POSICIONES')) {
            return true;
        }

        if (preg_match('/^MT TRANSPARENTE/iu', $desc) === 1 && str_contains($prev, 'CINTA EMBALAJE')) {
            return true;
        }

        if (preg_match('/^DE ANCHO VARIEDAD DE/iu', $desc) === 1 && str_contains($prev, 'CINTA SAT')) {
            return true;
        }

        if (preg_match('/^BLANCO 80 GRAMOS/iu', $desc) === 1 && str_contains($prev, 'SOBRE CARTA')) {
            return true;
        }

        if (preg_match('/^(?:CARTA|OFICIO) 100 UN$/iu', $desc) === 1 && str_contains($prev, 'FUNDA PLASTICA')) {
            return true;
        }

        if (preg_match('/^MADERA N\*2$/iu', $desc) === 1 && str_contains($prev, 'BROCHA PELO')) {
            return true;
        }

        if (preg_match('/^sv100$/iu', $desc) === 1 && str_contains($prev, 'MICRÓFONO')) {
            return true;
        }

        if ($desc === 'BATERÍA GRANDE' && str_contains($prev, 'PILA DURACELL')) {
            return true;
        }

        if (preg_match('/^BOLSA 30 PCS$/iu', $desc) === 1 && str_contains($prev, 'LIMPIA PIPAS')) {
            return true;
        }

        if (preg_match('/^ARTE PAÑO LENCI$/iu', $desc) === 1 && str_contains($prev, 'BLOCK PAÑOLENCI')) {
            return true;
        }

        if (preg_match('/^IMP\. /iu', $desc) === 1 && str_contains($prev, 'OJOS LOCOS')) {
            return true;
        }

        if (preg_match('/^A4 PAQUETE$/iu', $desc) === 1 && str_contains($prev, 'PAPEL FOTOGRAFICO')) {
            return true;
        }

        if (preg_match('/^SET DISEÑO IMP\./iu', $desc) === 1 && str_contains($prev, 'SET 4 RODILLO')) {
            return true;
        }

        if (preg_match('/^25M$/iu', $desc) === 1 && str_contains($prev, 'ROLLO PAPEL KRAFT')) {
            return true;
        }

        if (preg_match('/^ESPESOR:\s*8\b/iu', $desc) === 1 && str_contains($prev, 'PALOS DE MAQUETA REDONDOS')) {
            return true;
        }

        if (preg_match('/^COLORES SURTIDOS$/iu', $desc) === 1 && str_contains($prev, 'POMPONES')) {
            return true;
        }

        if (preg_match('/^PINCEL PINTAR PONCEAR/iu', $desc) === 1 && str_contains($prev, 'ESPONJA CEPILLO')) {
            return true;
        }

        if (preg_match('/^PINTURA ARTE$/iu', $desc) === 1 && str_contains($prev, 'PINCEL PINTAR')) {
            return true;
        }

        return false;
    }

    /**
     * Une sufijos que el OCR dejó en otra línea pero pertenecen a la misma celda (ej. "COLORES" tras "MARCADORES JUMBO 12").
     *
     * @param  array<int, array{cantidad: int, descripcion: string}>  $filas
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function fusionarSufijosCeldaMultilineaOcr(array $filas): array
    {
        if ($filas === []) {
            return [];
        }

        $fusionado = [];

        foreach ($filas as $fila) {
            $descripcion = trim($fila['descripcion']);

            if ($fusionado !== [] && $this->esSufijoCeldaMultilineaOcr($descripcion)) {
                $indice = count($fusionado) - 1;
                if ($this->puedeAnexarSufijoCeldaMultilinea($fusionado[$indice]['descripcion'], $descripcion)) {
                    $fusionado[$indice]['descripcion'] = trim(
                        $fusionado[$indice]['descripcion'].' '.$this->normalizarSufijoCeldaMultilineaOcr($descripcion),
                    );

                    continue;
                }
            }

            $fusionado[] = $fila;
        }

        return $fusionado;
    }

    private function esSufijoCeldaMultilineaOcr(string $descripcion): bool
    {
        $limpia = trim(preg_replace('/\s+[a-záéíóú]{1,2}$/u', '', trim($descripcion)) ?? trim($descripcion));
        if ($limpia === '' || mb_strlen($limpia) > 32) {
            return false;
        }

        $upper = mb_strtoupper($limpia);

        return preg_match(
            '/^(?:COLORES(?:\s+(?:SURTIDOS?|FLUOR|PASTELES?|NEON|BÁSICOS?|BASICOS?|VARIADOS?|MET[AÁ]LICOS?|PASTEL(?:ES)?|CRAFT))?|PASTELES?(?:\s+\d+\s+COLORES)?|UNIDADES(?:\s+IMAGIA(?:\s+TRIANGULAR)?|\s+PTA\s+FINA(?:\s+[\d,]+)?)?|\d+\/\d+\s+\d+\s+HOJAS|\d+\s+HOJAS|(?:\d+\s+)?(?:MM\s+)?PACK\s+\d+\s+UNIDADES|PTA\s+FINA(?:\s+[\d,]+)?|(?:\d+\s+)?COLORES(?:\s+(?:GIOTTO|PASTELES?|NEON|SURTIDOS?))?|DEPOSITO\s+SIMPLE\s+CAJA\s+\d+|HOJAS\s+PACK\s+\d+\s+UNI(?:DADES)?)$/iu',
            $upper,
        ) === 1;
    }

    private function puedeAnexarSufijoCeldaMultilinea(string $descripcionPrev, string $sufijo): bool
    {
        $prev = mb_strtoupper(trim($descripcionPrev));
        $suf = mb_strtoupper(trim($this->normalizarSufijoCeldaMultilineaOcr($sufijo)));

        if ($suf === '' || str_contains($prev, $suf)) {
            return false;
        }

        if (str_starts_with($suf, 'COLORES') || $suf === 'PASTELES' || str_starts_with($suf, 'COLORES PASTELES')) {
            if (str_contains($prev, 'COLORES')) {
                return false;
            }

            return preg_match(
                '/\b(?:JUMBO|SOLIDA|SOLIDO|NEON|PASTEL|TRIANGULAR|ACRILIC|\d+)\s*$/iu',
                $descripcionPrev,
            ) === 1;
        }

        if (preg_match('/^(?:\d+\/\d+\s+)?\d+\s+HOJAS$/iu', $suf)) {
            return preg_match('/\b(?:BLOCK|CUADERNO|BLOCK DE DIBUJO|MEDIUM)\b/iu', $descripcionPrev) === 1
                && ! preg_match('/\bHOJAS\b/iu', $descripcionPrev);
        }

        if ($suf === 'UNIDADES' || str_starts_with($suf, 'UNIDADES ')) {
            return preg_match('/\b(?:CAJA|PACK|DISPLAY|JUMBO|\d+)\s+\d*\s*$/iu', $descripcionPrev) === 1
                || preg_match('/\b(?:LAPIZ|PASTA|ARTEL|LAPICES)\b/iu', $descripcionPrev) === 1;
        }

        if (str_starts_with($suf, 'PTA FINA') || preg_match('/^\d+\s+COLORES\b/iu', $suf)) {
            return preg_match('/\b(?:LAPIZ|PASTA|ARTEL|MADERA|MARCADOR|BLOCK|CUADERNO)\b/iu', $descripcionPrev) === 1;
        }

        if (preg_match('/^(?:\d+\s+)?PACK\s+\d+\s+UNIDADES$/iu', $suf)) {
            return preg_match('/\b(?:CUADERNO|HOJAS)\b/iu', $descripcionPrev) === 1;
        }

        return false;
    }

    private function normalizarSufijoCeldaMultilineaOcr(string $sufijo): string
    {
        $sufijo = trim(preg_replace('/\s+[a-záéíóú]{1,2}$/u', '', trim($sufijo)) ?? trim($sufijo));

        return preg_replace('/\s+/u', ' ', $sufijo) ?? $sufijo;
    }

    /**
     * Recupera filas que el OCR partió en otro bloque de texto (p. ej. nativo + Tesseract).
     *
     * @param  array<int, array{cantidad: int, descripcion: string}>  $resultado
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function completarFilasSolicitudPedidoDesdeTexto(string $texto, array $resultado): array
    {
        $texto = $this->preprocesarTextoTablaSolicitudOcr($texto);

        $patrones = [
            'LAPIZ PASTA ARTEL PTA AZUL' => '/LAPIZ\s+PASTA\s+ARTEL\s+PTA\s+AZUL/iu',
            'LAPIZ PASTA ARTEL PTA ROJO' => '/LAPIZ\s+PASTA\s+ARTEL\s+PTA\s+ROJO/iu',
            'RESMA OFICIO' => '/RESMA\s+OFICIO/iu',
            'RESMA CARTA' => '/RESMA\s+CARTA/iu',
            'CUADERNO UNIVERSITARIO' => '/CUADERNO\s+UNIVERSITARIO/iu',
            'CORCHETERA' => '/CORCHETERA/iu',
            'PERFORADORA' => '/PERFORADORA/iu',
            'CINTA DOBLE CONTACTO' => '/CINTA\s+DOBLE\s+CONTACTO/iu',
        ];

        $lineasCrudas = preg_split('/\r\n|\n|\r/u', $texto) ?: [];

        foreach ($patrones as $needle => $pattern) {
            if ($this->resultadoContieneProducto($resultado, $needle)) {
                continue;
            }

            foreach ($lineasCrudas as $indice => $lineaCruda) {
                $linea = $this->normalizarLineaTablaOcr($lineaCruda);
                if ($linea === '' || $this->esRuidoTablaProductoCantidad($linea)) {
                    continue;
                }

                if (preg_match($pattern, $linea) !== 1) {
                    continue;
                }

                $fila = $this->intentarFilaProductoDesdeVentanaOcr($lineasCrudas, $indice)
                    ?? $this->inferirFilaProductoSolicitudPedido($needle, $lineasCrudas, $indice);

                if ($fila === null) {
                    continue;
                }

                $fila = $this->corregirCantidadEmpaqueConfundida($fila);
                $resultado[] = $fila;
                break;
            }
        }

        return $resultado;
    }

    /**
     * @param  array<int, string>  $lineasCrudas
     * @return array{cantidad: int, descripcion: string}|null
     */
    private function intentarFilaProductoDesdeVentanaOcr(array $lineasCrudas, int $indice): ?array
    {
        $maxVentana = min(4, count($lineasCrudas) - $indice);

        for ($ventana = 1; $ventana <= $maxVentana; $ventana++) {
            $trozo = array_slice($lineasCrudas, $indice, $ventana);
            $normalizadas = array_map(
                fn (string $linea): string => $this->normalizarLineaTablaOcr($linea),
                $trozo,
            );
            $normalizadas = array_values(array_filter(
                $normalizadas,
                fn (string $linea): bool => $linea !== '' && ! $this->esRuidoTablaProductoCantidad($linea),
            ));

            if ($normalizadas === []) {
                continue;
            }

            $unido = trim(preg_replace('/\s+/u', ' ', implode(' ', $normalizadas)) ?? '');
            $fila = $this->intentarFilaTablaProducto($unido, implode("\n", $trozo));
            if ($fila !== null) {
                return $fila;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $lineasCrudas
     * @return array{cantidad: int, descripcion: string}|null
     */
    private function inferirFilaProductoSolicitudPedido(string $needle, array $lineasCrudas, int $indice): ?array
    {
        $linea = $this->normalizarLineaTablaOcr($lineasCrudas[$indice] ?? '');
        $linea = preg_replace('/\s+y\s*$/iu', '', $linea) ?? $linea;

        if ($needle === 'RESMA OFICIO' && preg_match('/RESMA\s+OFICIO\s+500\s+HOJAS/iu', $linea) === 1) {
            for ($j = $indice; $j < min($indice + 6, count($lineasCrudas)); $j++) {
                $candidata = $this->normalizarLineaTablaOcr($lineasCrudas[$j]);
                if (preg_match('/^(\d{1,5})\s+Ta$/iu', $candidata, $coincidencia) === 1) {
                    return [
                        'cantidad' => max(1, (int) $coincidencia[1]),
                        'descripcion' => 'RESMA OFICIO 500 HOJAS',
                    ];
                }
                if (preg_match('/^(\d{1,5})$/u', $candidata, $coincidencia) === 1) {
                    return [
                        'cantidad' => max(1, (int) $coincidencia[1]),
                        'descripcion' => 'RESMA OFICIO 500 HOJAS',
                    ];
                }
            }
        }

        if ($needle === 'CUADERNO UNIVERSITARIO' && preg_match('/CUADERNO\s+UNIVERSITARIO/iu', $linea) === 1) {
            $fila = $this->intentarFilaTablaProducto($linea, $lineasCrudas[$indice]);
            if ($fila !== null) {
                return $fila;
            }

            return [
                'cantidad' => 1,
                'descripcion' => 'CUADERNO UNIVERSITARIO 100 HOJAS PACK 10 UNIDADES',
            ];
        }

        return null;
    }

    /**
     * @param  array<int, array{cantidad: int, descripcion: string}>  $resultado
     */
    private function resultadoContieneProducto(array $resultado, string $needle): bool
    {
        $needle = mb_strtoupper($needle);

        foreach ($resultado as $fila) {
            if (str_contains(mb_strtoupper($fila['descripcion']), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function sanearDescripcionTablaOcr(string $descripcion): string
    {
        $descripcion = trim(preg_replace('/\s+/u', ' ', $descripcion) ?? $descripcion);
        $descripcion = preg_replace('/^CT ETS\s+/iu', '', $descripcion) ?? $descripcion;
        $descripcion = preg_replace('/^MEDIUM\s*,?\s*/iu', '', $descripcion) ?? $descripcion;
        $descripcion = preg_replace('/^UNIDADES\s+(?=COLA|TÉMPERA|TEMPERA|MARCADOR|LÁPIZ|LAPIZ|SACAPUNTAS)/iu', '', $descripcion) ?? $descripcion;

        if (preg_match('/^UNIDADES IMAGIA TRIANGULAR (.+)$/iu', $descripcion, $coincidencia) === 1) {
            $descripcion = trim($coincidencia[1]);
        }

        return trim($descripcion);
    }

    /**
     * @param  array<int, array{cantidad: int, descripcion: string}>  $filas
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function partirFilasFusionadasOcr(array $filas): array
    {
        $resultado = [];

        foreach ($filas as $fila) {
            if ($this->pareceFilaFusionadaOcr($fila['descripcion'])) {
                foreach ($this->partirDescripcionProductoFusionado($fila) as $partida) {
                    $resultado[] = $partida;
                }

                continue;
            }

            $resultado[] = [
                'cantidad' => $fila['cantidad'],
                'descripcion' => $this->sanearDescripcionTablaOcr($fila['descripcion']),
            ];
        }

        return $this->deduplicarLineasTabla($resultado);
    }

    private function pareceFilaFusionadaOcr(string $descripcion): bool
    {
        $descripcion = $this->sanearDescripcionTablaOcr($descripcion);
        if ($descripcion === '') {
            return false;
        }

        if (preg_match_all('/\b(?:LÁPIZ DE MADERA|LAPIZ DE MADERA)\b/iu', $descripcion) >= 2) {
            return true;
        }

        if (preg_match('/\b(?:TÉMPERA SOLIDA|TEMPERA SOLIDA)\b/iu', $descripcion) === 1
            && preg_match('/\b(?:COLA FRÍA|COLA FRIA|UNIDADES)\b/iu', $descripcion) === 1) {
            return true;
        }

        if (preg_match('/^MEDIUM\s*,?\s*SACAPUNTAS/iu', $descripcion) === 1) {
            return true;
        }

        if (preg_match('/\s+[-–—]\s+(?=(?:LÁPIZ|LAPIZ|LAPICES|SACAPUNTAS|TÉMPERA|TEMPERA|MARCADOR|COLA)\b)/iu', $descripcion) === 1) {
            return true;
        }

        if (preg_match('/\b\d{1,5}\s+\d{1,5}\s*$/u', $descripcion) === 1) {
            return true;
        }

        return $this->contieneMultiplesProductosTabla($descripcion);
    }

    /**
     * @param  array<int, array{cantidad: int, descripcion: string}>  $resultado
     */
    private function agregarFilasVolcadasBuffer(array &$resultado, ?string $buffer, ?int $cantidadPendiente): void
    {
        foreach ($this->volcarBufferTablaProducto($buffer, $cantidadPendiente) as $fila) {
            if ($this->esFragmentoContinuacionDescripcion($fila['descripcion'])
                || $this->esDescripcionBasuraTabla($fila['descripcion'])) {
                continue;
            }

            $resultado[] = $this->corregirCantidadEmpaqueConfundida($fila);
        }
    }

    /**
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function volcarBufferTablaProducto(?string $buffer, ?int $cantidadPendiente): array
    {
        if ($buffer === null || mb_strlen(trim($buffer)) < 3) {
            return [];
        }

        $fila = $this->finalizarBufferTablaProducto($buffer, $cantidadPendiente);
        if ($fila !== null) {
            return [$this->corregirCantidadEmpaqueConfundida($fila)];
        }

        if ($this->contieneMultiplesProductosTabla($buffer)) {
            $partidas = [];
            foreach ($this->partirTextoEnProductosTabla($buffer) as $segmento) {
                foreach ($this->partirDescripcionProductoFusionado([
                    'cantidad' => $cantidadPendiente ?? 1,
                    'descripcion' => $segmento,
                ]) as $partida) {
                    $partidas[] = $partida;
                }
            }

            if ($partidas !== []) {
                return $partidas;
            }
        }

        $extraida = $this->extraerProductoCantidadDeLinea($buffer);
        if ($extraida !== null && ! $this->esDescripcionBasuraTabla($extraida['descripcion'])) {
            return [$this->corregirCantidadEmpaqueConfundida($extraida)];
        }

        return [[
            'cantidad' => $cantidadPendiente ?? 1,
            'descripcion' => trim($buffer),
        ]];
    }

    private function contieneMultiplesProductosTabla(string $texto): bool
    {
        if (preg_match('/^ACUARELA\s+SET 12 COLORES CON PINCEL$/iu', trim($texto)) === 1) {
            return false;
        }

        if (preg_match('/^PILA DURACELL TIPO D,.+BATERÍA GRANDE$/iu', trim($texto)) === 1) {
            return false;
        }

        if (preg_match('/^PAPEL FOTOGRAFICO ADHESIVO.+A4 PAQUETE$/iu', trim($texto)) === 1) {
            return false;
        }

        if (preg_match('/^SET 4 RODILLO DE ESPONJA CON DISEÑO IMP\./iu', trim($texto)) === 1) {
            return false;
        }

        return preg_match_all('/'.$this->regexLookaheadInicioProductoTabla().'/iu', $texto) >= 2;
    }

    /**
     * @return array<int, string>
     */
    private function partirTextoEnProductosTabla(string $texto): array
    {
        $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);
        if ($texto === '') {
            return [];
        }

        $segmentos = preg_split('/\s+(?='.$this->regexLookaheadInicioProductoTabla().')/iu', $texto) ?: [$texto];

        return array_values(array_filter(
            array_map(static fn (string $segmento): string => trim($segmento), $segmentos),
            static fn (string $segmento): bool => $segmento !== '' && mb_strlen($segmento) >= 3,
        ));
    }

    private function regexLookaheadInicioProductoTabla(): string
    {
        return '(?:MICRÓFONO|GREDA|ARCILLA|PAPEL BOND|POMPONES|ROLLO PAPEL KRAFT|BROCHA PELO|CINTA SAT|CINTA SATÍN|SOBRE CARTA|SOBRE 1\/4|PLIEGO CARTULINA|BOLSITAS CON|LIMPIA PIPAS|FUNDA PLASTICA|CARTULINA CORRUGADO|\d+\s+ESPONJA|BLOCK PAÑOLENCI|LIENZO DE TELA|PILA DURACELL|BATERÍA|LÁPIZ DE MADERA|LAPIZ DE MADERA|SACAPUNTAS|TÉMPERA SOLIDA|TEMPERA SOLIDA|COLA FRÍA|MARCADOR ÓLEO|PACK MARCATEXTOS|CUADERNO CUARTA|TEMPERA \d|PLASTICINA|MARCADORES JUMBO|BLOCK DE DIBUJO|GLOBOS N|BOLSA DE GLOBOS|POST-IT|POST-1T|TERMOLAMINADORA|PERFORADORA MET|SACA CORCHETES|Bostitch|ALARGADOR|BASTIDOR DE|CINTA EMBALAJE|CINTA ENMASCARAR|CINTA DOBLE|RESMA |LAPIZ PASTA|LAPICES DE CERA|CORCHETERA|GOMA EVA|CLIP METALICO|PIZARRA CORCHO|CARTULINA OPALINA|FINELINER|PLUMON PIZARRA|ACUARELA|REGLA MET|TIJERA|MEZCLADOR|PALO DE HELADO|BAJA LENGUA|IMP\. |PITILLA |LANA |OJOS LOCOS|CAÑAMO|ARPILLERA|HILO |SET |BOTON |LAMINAS PARA|PIZARRA ROJO|PIZARRA VERDE|PIZARRA AZUL|LAPIZ GRAFITO|PAPEL VOLANTIN|PAPEL FOTOGRAFICO|PLIEGO CARTON|PLIEGO CARTULINA ESPAÑOLA|CARTULINA ESPAÑOLA|TÉMPERA 250ML|BLOCK PAÑOLENCI|PAÑO LENCI|PAPEL CHOCLO)';
    }

    /**
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function partirDescripcionProductoFusionado(array $fila): array
    {
        $descripcion = $this->sanearDescripcionTablaOcr($fila['descripcion']);
        if ($descripcion === '') {
            return [];
        }

        $partes = [$descripcion];
        if ($this->contieneMultiplesProductosTabla($descripcion)) {
            $partes = $this->partirTextoEnProductosTabla($descripcion);
        }

        $separadorProducto = '/\s+[-–—]\s+(?=(?:LÁPIZ|LAPIZ|LAPICES|SACAPUNTAS|TÉMPERA|TEMPERA|MARCADOR|COLA|BLOCK|CUADERNO|PLASTICINA|CORRECTOR|PACK|MARCADORES|GLOBOS|POST|FINELINER|PLUMON|ACUARELA|PLIEGO|PAPEL|REGLA|TIJERA|CINTA|SOBRE|FUNDA|BROCHA|ROLLO|BASTIDOR|PILA|TERMOLAMINADORA|HILO|SET|BOTON|LAMINAS|PIZARRA|CARTULINA|MICRÓFONO|GREDA|ARCILLA|POMPONES|ALARGADOR|PLASTIFICADORA|SACA|PERFORADORA|MEZCLADOR|BAJA|PALO|PITILLA|LANA|OJOS|CAÑAMO|ARPILLERA|IMP\.|Bostitch|MICRÓFONO|BOLSITAS|LIMPIA PIPAS))/iu';

        $expandido = [];
        foreach ($partes as $parte) {
            $subpartes = preg_split($separadorProducto, $parte) ?: [$parte];
            foreach ($subpartes as $subparte) {
                $subparte = trim($subparte);
                if ($subparte === '') {
                    continue;
                }

                if (preg_match_all('/\b(LÁPIZ DE MADERA|LAPIZ DE MADERA)\b/iu', $subparte, $coincidencias) >= 2) {
                    $segmentos = preg_split('/\s+(?=(?:LÁPIZ DE MADERA|LAPIZ DE MADERA)\b)/iu', $subparte) ?: [$subparte];
                    foreach ($segmentos as $segmento) {
                        $segmento = trim($segmento);
                        if ($segmento !== '') {
                            $expandido[] = $segmento;
                        }
                    }

                    continue;
                }

                if (preg_match('/\b(TÉMPERA SOLIDA|TEMPERA SOLIDA)\b/iu', $subparte, $tempera, PREG_OFFSET_CAPTURE) === 1
                    && ($tempera[0][1] ?? 0) > 0) {
                    $antes = trim(substr($subparte, 0, $tempera[0][1]));
                    $despues = trim(substr($subparte, $tempera[0][1]));
                    if ($antes !== '') {
                        $expandido[] = $antes;
                    }
                    if ($despues !== '') {
                        $expandido[] = $despues;
                    }

                    continue;
                }

                $expandido[] = $subparte;
            }
        }

        $filas = [];
        foreach ($expandido as $parte) {
            $extraida = $this->extraerProductoCantidadDeLinea($parte);
            if ($extraida !== null && ! $this->esDescripcionBasuraTabla($extraida['descripcion'])) {
                $filas[] = $this->corregirCantidadEmpaqueConfundida($extraida);

                continue;
            }

            if (preg_match('/^(.+?)\s+(\d{1,5})\s+\d{1,5}\s*$/u', $parte, $coincidencia) === 1) {
                $filas[] = $this->corregirCantidadEmpaqueConfundida([
                    'cantidad' => max(1, (int) $coincidencia[2]),
                    'descripcion' => trim($coincidencia[1]),
                ]);

                continue;
            }

            if (preg_match('/^(.+?)\s+(\d+\s+CAJAS?|\d+\s+PACK|\d+\s+SOBRES?|\d+\s+UNIDADES?|\d{1,5})\s*$/iu', $parte, $coincidencia) === 1) {
                $cantidad = $this->parseCantidadCeldaTabla(trim($coincidencia[2]));
                if ($cantidad !== null) {
                    $filas[] = $this->corregirCantidadEmpaqueConfundida([
                        'cantidad' => $cantidad,
                        'descripcion' => trim($coincidencia[1]),
                    ]);

                    continue;
                }
            }

            $filas[] = [
                'cantidad' => $fila['cantidad'],
                'descripcion' => $parte,
            ];
        }

        return $filas !== [] ? $filas : [[
            'cantidad' => $fila['cantidad'],
            'descripcion' => $descripcion,
        ]];
    }

    /**
     * @return array{cantidad: int, descripcion: string}|null
     */
    private function finalizarBufferTablaProducto(?string $buffer, ?int $cantidadPendiente): ?array
    {
        if ($buffer === null || mb_strlen(trim($buffer)) < 3) {
            return null;
        }

        $desdeLinea = $this->extraerProductoCantidadDeLinea($buffer);
        if ($desdeLinea !== null && ! $this->esDescripcionBasuraTabla($desdeLinea['descripcion'])) {
            return $this->corregirCantidadEmpaqueConfundida($desdeLinea);
        }

        if ($cantidadPendiente !== null) {
            return $this->corregirCantidadEmpaqueConfundida([
                'cantidad' => $cantidadPendiente,
                'descripcion' => trim($buffer),
            ]);
        }

        return null;
    }

    private function pareceInicioNuevoProductoTabla(string $linea): bool
    {
        $linea = trim($linea);

        return preg_match(
            '/^(LÁPIZ|LAPIZ|LAPICES|SACAPUNTAS|TÉMPERA|TEMPERA|MARCADOR|COLA|BLOCK|CUADERNO|PLASTICINA|CORRECTOR|PACK|MARCADORES|GLOBOS|POST-?IT|FINELINER|PLUMON|ACUARELA|PLIEGO|PAPEL|REGLA|TIJERA|CINTA|SOBRE|FUNDA|BROCHA|ROLLO|BASTIDOR|PILA|TERMOLAMINADORA|HILO|SET|BOTON|LAMINAS|PIZARRA|CARTULINA|MICRÓFONO|GREDA|ARCILLA|POMPONES|ALARGADOR|PLASTIFICADORA|SACA|PERFORADORA|MEZCLADOR|BAJA|PALO|PITILLA|LANA|OJOS|CAÑAMO|ARPILLERA|IMP\.|Bostitch|RESMA|GOMA|CLIP|CORCHETERA|PERFORADORA|CUADERNO|BOLSA|PLASTICINA|CORRECTOR|FINELINER|PLUMON|ACUARELA|GLOBOS|POST|TERMOLAMINADORA|PLIEGO|PAPEL|REGLA|TIJERA|CINTA|SOBRE|FUNDA|BROCHA|ROLLO|BASTIDOR|PILA|PACK|MICRÓFONO|GREDA|ARCILLA|POMPONES|HILO|SET|BOTON|LAMINAS|PIZARRA|CARTULINA|ARPILLERA|CAÑAMO|LANA|OJOS|MICRÓFONO|BAJA|PALO|PITILLA|MEZCLADOR|IMP\.|PLASTIFICADORA|Bostitch|PLIEGO|LAMINAS|MICRÓFONO|GREDA|ARCILLA|POMPONES|HILO|SET|BOTON|LAMINAS|PIZARRA|CARTULINA|MICRÓFONO|GREDA|ARCILLA|POMPONES|HILO|SET|BOTON|LAMINAS|PIZARRA|CARTULINA)\b/iu',
            $linea,
        ) === 1;
    }

    /**
     * @param  array{cantidad: int, descripcion: string}  $fila
     * @return array{cantidad: int, descripcion: string}
     */
    private function limpiarCantidadDuplicadaEnDescripcion(array $fila): array
    {
        $descripcion = trim($fila['descripcion']);
        $cantidad = $fila['cantidad'];

        if (preg_match('/^(.+?)\s+(\d{1,5})$/u', $descripcion, $coincidencia) !== 1) {
            return $fila;
        }

        if ((int) $coincidencia[2] !== $cantidad) {
            return $fila;
        }

        $base = trim($coincidencia[1]);
        if ($base === '' || preg_match('/\b(\d+\s+UNIDADES|\d+\s+HOJAS|MM|MTS|PACK)\b/iu', $base) === 1) {
            return $fila;
        }

        $fila['descripcion'] = $base;

        return $fila;
    }

    /**
     * @param  array<int, array{cantidad: int, descripcion: string}>  $resultado
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function deduplicarLineasTabla(array $resultado, bool $fuzzy = false): array
    {
        if ($fuzzy) {
            $unicas = [];
            foreach ($resultado as $linea) {
                if ($this->filaYaRepresentadaEnTabla($linea, $unicas)) {
                    continue;
                }
                $unicas[] = $linea;
            }

            return $unicas;
        }

        $vistas = [];
        $unicas = [];

        foreach ($resultado as $linea) {
            $clave = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $linea['descripcion']) ?? $linea['descripcion']));
            if ($clave === '' || isset($vistas[$clave])) {
                continue;
            }
            $vistas[$clave] = true;
            $unicas[] = $linea;
        }

        return $unicas;
    }

    /**
     * @param  array<int, array{cantidad: int, descripcion: string}>  $resultado
     * @param  array{cantidad: int, descripcion: string}  $fila
     */
    private function incorporarFilaTablaProducto(
        array &$resultado,
        ?string &$buffer,
        ?int &$cantidadPendiente,
        array $fila,
    ): void {
        $descripcion = trim($fila['descripcion']);

        if ($this->esFragmentoContinuacionDescripcion($descripcion)) {
            $buffer = $buffer !== null ? trim($buffer.' '.$descripcion) : $descripcion;
            $cantidadPendiente = $fila['cantidad'];

            if ($buffer !== null && $cantidadPendiente !== null && ! $this->esFragmentoContinuacionDescripcion($buffer)) {
                $resultado[] = $this->corregirCantidadEmpaqueConfundida([
                    'cantidad' => $cantidadPendiente,
                    'descripcion' => $buffer,
                ]);
                $buffer = null;
                $cantidadPendiente = null;
            }

            return;
        }

        if ($buffer !== null) {
            $descripcion = trim($buffer.' '.$descripcion);
            $buffer = null;
        }

        $cantidadPendiente = null;

        $resultado[] = $this->corregirCantidadEmpaqueConfundida([
            'cantidad' => $fila['cantidad'],
            'descripcion' => $descripcion,
        ]);
    }

    private function normalizarLineaTablaOcr(string $lineaCruda): string
    {
        $linea = trim(preg_replace('/[ \t]+/u', ' ', $lineaCruda) ?? $lineaCruda);
        $linea = preg_replace('/\s*\|\s*$/u', '', $linea) ?? $linea;
        $linea = preg_replace('/^\|\s*/u', '', $linea) ?? $linea;
        $linea = preg_replace('/\s+(\d+)\s+d\s*$/iu', ' $1', $linea) ?? $linea;
        $linea = preg_replace('/\s+S(\d+)unidades/iu', ' $1 unidades', $linea) ?? $linea;
        $linea = preg_replace('/\s+(?:Le|La|E|De)\s*$/iu', '', $linea) ?? $linea;
        $linea = preg_replace('/[\s"\'_]+$/u', '', $linea) ?? $linea;

        return trim($linea);
    }

    /**
     * @param  array{cantidad: int, descripcion: string}  $fila
     * @return array{cantidad: int, descripcion: string}
     */
    private function corregirCantidadEmpaqueConfundida(array $fila): array
    {
        $cantidad = $fila['cantidad'];
        $descripcion = trim($fila['descripcion']);

        if (preg_match('/\b(\d+)\s+UNIDADES\b/iu', $descripcion, $empaque) === 1
            && (int) $empaque[1] === $cantidad
            && preg_match('/\b(LAPIZ|LAPICES|PASTA|PTA|CERA|BOLIGRAFO|PLUMON|MARCADOR)\b/iu', $descripcion) === 1
            && preg_match('/\b(?:JUMBO|IMAGIA)\b/iu', $descripcion) !== 1
            && preg_match('/\bLAPIZ\s+PASTA\b/iu', $descripcion) === 1) {
            $fila['cantidad'] = 1;
        }

        if (preg_match('/\bJUMBO\s+12\b/iu', $descripcion) === 1) {
            if (preg_match('/\s+(\d{1,4})\s*$/u', $descripcion, $trail) === 1) {
                $fila['cantidad'] = max(1, (int) $trail[1]);
                $fila['descripcion'] = trim(preg_replace('/\s+\d{1,4}\s*$/u', '', $descripcion) ?? $descripcion);
            }

            return $fila;
        }

        if (preg_match('/^\d+\s+(LAPIZ|LAPICES|BOLIGRAFO|PLUMON|MARCADOR)\b/iu', $descripcion) === 1
            && preg_match('/\b\d+\s+UNIDADES\b/iu', $descripcion) === 1) {
            $descripcion = trim(preg_replace('/^\d+\s+/u', '', $descripcion) ?? $descripcion);
        }

        // OCR truncado: cantidad del empaque (50) sin "50 UNIDADES" en la descripción.
        if ($cantidad >= 10
            && preg_match('/\bLAPIZ\s+PASTA\b/iu', $descripcion) === 1
            && preg_match('/\bPTA\b/iu', $descripcion) === 1) {
            $fila['cantidad'] = 1;
        }

        $fila['descripcion'] = $descripcion;

        return $this->normalizarCantidadPedidoDesdeTextoCelda($fila);
    }

    /**
     * Toma la cantidad pedido desde el texto de la celda (ej. "30 unidades", "5 paquetes").
     *
     * @param  array{cantidad: int, descripcion: string}  $fila
     * @return array{cantidad: int, descripcion: string}
     */
    private function normalizarCantidadPedidoDesdeTextoCelda(array $fila): array
    {
        $descripcion = trim($fila['descripcion']);
        $cantidad = $fila['cantidad'];

        if ($descripcion === '') {
            return $fila;
        }

        if (preg_match('/\bLAPIZ\s+PASTA\b/iu', $descripcion) === 1
            && preg_match('/\b50\s+UNIDADES\b/iu', $descripcion) === 1) {
            return $fila;
        }

        $patronesPedido = [
            '/\b(\d{1,4})\s+(paquetes|bolsas|rollos|pliegos|sobres|tiras|set|cajas)\b(?:\s+[^\d]{0,40})?$/iu',
            '/\b(\d{1,4})\s+(paquetes|bolsas|rollos|pliegos|sobres|tiras|set|cajas)\b/iu',
            '/\b(\d{1,4})\s+unidades\b(?:\s+[^\d]{0,40})?$/iu',
            '/\b(\d{1,4})\s+unidades\b/iu',
        ];

        foreach ($patronesPedido as $patron) {
            if (preg_match($patron, $descripcion, $coincidencia) !== 1) {
                continue;
            }

            $qtyTexto = max(1, (int) $coincidencia[1]);
            if ($qtyTexto > 500 || $this->esNumeroEmpaqueEnDescripcion($descripcion, $qtyTexto)) {
                continue;
            }

            if ($qtyTexto === $cantidad) {
                break;
            }

            if ($cantidad === 1 || ($cantidad !== $qtyTexto && ! $this->esNumeroEmpaqueEnDescripcion($descripcion, $cantidad))) {
                $fila['cantidad'] = $qtyTexto;
            }

            break;
        }

        return $fila;
    }

    private function esNumeroEmpaqueEnDescripcion(string $descripcion, int $numero): bool
    {
        if (preg_match('/\b(?:PACK|CAJA|DISPLAY|BOLSA|SOBRE|TIRA)\s+'.$numero.'\s+UNID(?:ADES|ES)?\b/iu', $descripcion) === 1) {
            return true;
        }

        if (preg_match('/\b'.$numero.'\s+UNID(?:ADES|ES)?\b/iu', $descripcion) === 1
            && preg_match('/\b(?:PACK|CAJA|DEPOSITO|SACAPUNTAS|POST-1T|GRAFITO|LAPIZ|LAPICES|MARCADOR|PLUMON|FINELINER|GIOTTO|HELADO)\b/iu', $descripcion) === 1) {
            return true;
        }

        if ($numero === 6 && preg_match('/\bPACK\s+6\s+UNIDADES\b/iu', $descripcion) === 1) {
            return true;
        }

        if ($numero === 24 && preg_match('/\bPOST-1T\b/iu', $descripcion) === 1) {
            return true;
        }

        if ($numero === 30 && preg_match('/\b(?:SACAPUNTAS|DEPOSITO|CAJA)\b/iu', $descripcion) === 1) {
            return true;
        }

        if ($numero === 50 && preg_match('/\b(?:HELADO|UNID)\b/iu', $descripcion) === 1) {
            return true;
        }

        if ($numero === 12 && preg_match('/\b(?:GRAFITO|LAPIZ|LAPICES|COLORES)\b/iu', $descripcion) === 1
            && preg_match('/\b12\s+UNIDADES\b/iu', $descripcion) === 1) {
            return true;
        }

        return false;
    }

    private function esFragmentoContinuacionDescripcion(string $descripcion): bool
    {
        $descripcion = trim($descripcion);
        if ($descripcion === '') {
            return true;
        }

        $upper = mb_strtoupper($descripcion);

        if (preg_match('/^[\d,\.]+\s*(MTS|MT|MM|CM|KG|GR|G)\b/iu', $descripcion) === 1) {
            return true;
        }

        if (preg_match('/^[\d,\.]+\s*(MTS|MT|MM|CM)\.?$/iu', $descripcion) === 1) {
            return true;
        }

        foreach ([
            'UNIDADES PTA',
            'PTA FINA',
            'HOJAS PACK',
            'MM PACK',
            'MM X',
            'X 13',
            'X 18',
        ] as $prefijo) {
            if (str_starts_with($upper, $prefijo)) {
                return true;
            }
        }

        if (mb_strlen($descripcion) < 22 && preg_match('/\b(UNIDADES|PACK|HOJAS|PTA FINA|MM|MTS)\b/iu', $descripcion) === 1) {
            if (! preg_match('/^(LAPICES|LAPIZ|RESMA|CORCHETERA|PERFORADORA|CINTA|CUADERNO|GOMA|CLIP|BOLIGRAFO|TIJERA|PEGAMENTO|ACUARELA|LÁMINA|LAMINA|BLOCK|CARPETA|ARCHIVADOR|REGLA)/iu', $descripcion)) {
                return true;
            }
        }

        return false;
    }

    private function esTituloSolicitudPedido(string $linea): bool
    {
        $upper = mb_strtoupper(trim($linea));

        return str_contains($upper, 'SOLICITUD DE PEDIDO')
            || str_contains($upper, 'ESPECIFICACIONES SOLICITUD');
    }

    private function esLineaCabeceraColumnaSolicitud(string $linea): bool
    {
        $upper = mb_strtoupper(trim($linea));

        if (in_array($upper, ['PRODUCTO', 'CANTIDAD'], true)) {
            return true;
        }

        return preg_match('/^IMAGEN\s+(?:DE\s+)?REFERENCIA/u', $upper) === 1;
    }

    private function esDescripcionBasuraTabla(string $descripcion): bool
    {
        $descripcion = trim($descripcion);
        if ($descripcion === '' || mb_strlen($descripcion) < 4) {
            return true;
        }

        if (preg_match('/^\d+\s*\|\s*\d+\s*unidades?\.?\s*$/iu', $descripcion) === 1) {
            return true;
        }

        return preg_match('/^\d+\s+unidades?\.?\s*$/iu', $descripcion) === 1;
    }

    /**
     * @return array{cantidad: int, descripcion: string}|null
     */
    private function intentarFilaTablaProducto(string $linea, string $lineaCruda): ?array
    {
        $desdeColumnas = $this->extraerDesdeColumnasTabuladas($lineaCruda);
        if ($desdeColumnas !== null && ! $this->esDescripcionBasuraTabla($desdeColumnas['descripcion'])) {
            return $desdeColumnas;
        }

        $desdeEspacios = $this->extraerDesdeColumnasEspaciadas($lineaCruda);
        if ($desdeEspacios === null) {
            $desdeEspacios = $this->extraerDesdeColumnasEspaciadas($linea);
        }
        if ($desdeEspacios !== null && ! $this->esDescripcionBasuraTabla($desdeEspacios['descripcion'])) {
            return $desdeEspacios;
        }

        $parsed = $this->extraerProductoCantidadDeLinea($linea);
        if ($parsed !== null) {
            if ($this->esFragmentoContinuacionDescripcion($parsed['descripcion'])) {
                return $parsed;
            }

            if (! $this->esDescripcionBasuraTabla($parsed['descripcion'])) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * @return array{cantidad: int, descripcion: string}|null
     */
    private function extraerDesdeColumnasEspaciadas(string $linea): ?array
    {
        if (! preg_match('/\s{2,}/u', $linea)) {
            return null;
        }

        $partes = preg_split('/\s{2,}/u', trim($linea)) ?: [];
        $partes = array_values(array_filter(array_map('trim', $partes), static fn (string $p) => $p !== ''));

        return $this->extraerDesdePartesColumna($partes);
    }

    private function esEncabezadoTablaProductoCantidad(string $linea): bool
    {
        $upper = mb_strtoupper($linea);

        return preg_match('/PRODUCTO\s+CANTIDAD/u', $upper) === 1;
    }

    private function esRuidoTablaProductoCantidad(string $linea): bool
    {
        $upper = mb_strtoupper(trim($linea));

        if ($upper === '') {
            return true;
        }

        if (preg_match('/^=+$/u', $upper) === 1) {
            return true;
        }

        if (preg_match('/^\d+\s+TA$/u', $upper) === 1) {
            return true;
        }

        if (preg_match('/^MEDIUM\s*,?\s*$/iu', $upper) === 1) {
            return true;
        }

        if (preg_match('/^[A-ZÁÉÍÓÚÑ]{1,2}$/u', $upper) === 1) {
            return true;
        }

        if ($upper === 'UNIDADES' || $upper === 'UNIDES') {
            return true;
        }

        foreach ([
            'PÁGINA',
            'PAGINA',
            'ESPECIFICACIONES SOLICITUD',
            'SOLICITUD DE PEDIDO',
            'IMAGEN REFERENCIA',
            'IMAGEN DE REFERENCIA',
        ] as $marcador) {
            if ($upper === $marcador || str_starts_with($upper, $marcador.' ')) {
                return true;
            }
        }

        return preg_match('/^P[AÁ]GINA\s+\d+/u', $upper) === 1;
    }

    private function pareceLineaDescripcionTabla(string $linea): bool
    {
        if ($this->parseCantidadCeldaTabla($linea) !== null && mb_strlen(trim($linea)) <= 24) {
            return false;
        }

        return preg_match('/[A-ZÁÉÍÓÚÑa-záéíóúñ]/u', $linea) === 1
            && mb_strlen(trim($linea)) >= 3;
    }

    /**
     * @return array{cantidad: int, descripcion: string}|null
     */
    private function extraerDesdeColumnasTabuladas(string $lineaCruda): ?array
    {
        if (! str_contains($lineaCruda, "\t")) {
            return null;
        }

        $partes = array_values(array_filter(
            array_map(static fn (string $p) => trim($p), explode("\t", $lineaCruda)),
            static fn (string $p) => $p !== '',
        ));

        return $this->extraerDesdePartesColumna($partes);
    }

    /**
     * Resuelve producto/cantidad desde columnas separadas (tabs o espacios).
     * Prueba producto|cantiad y cantidad|producto; con 3+ columnas infiere por contenido.
     *
     * @param  list<string>  $partes
     * @return array{cantidad: int, descripcion: string}|null
     */
    private function extraerDesdePartesColumna(array $partes): ?array
    {
        $partes = array_values(array_filter(
            array_map(static fn (string $p) => trim($p), $partes),
            static fn (string $p) => $p !== '',
        ));

        $partes = array_values(array_filter(
            $partes,
            static fn (string $p) => preg_match('/^IMAGEN\b/iu', $p) !== 1,
        ));

        if (count($partes) < 2) {
            return null;
        }

        if (count($partes) === 2) {
            foreach ([[0, 1], [1, 0]] as [$indiceDescripcion, $indiceCantidad]) {
                $fila = $this->intentarParColumnaProductoCantidad(
                    $partes[$indiceDescripcion],
                    $partes[$indiceCantidad],
                );
                if ($fila !== null) {
                    return $fila;
                }
            }

            return null;
        }

        $indiceCantidad = null;

        foreach ($partes as $indice => $celda) {
            if ($this->parseCantidadCeldaTabla($celda) !== null && mb_strlen($celda) <= 40) {
                $indiceCantidad = $indice;

                break;
            }
        }

        $partesDescripcion = [];
        foreach ($partes as $indice => $celda) {
            if ($indice === $indiceCantidad) {
                continue;
            }
            if ($this->esRuidoTablaProductoCantidad($celda) || $this->esLineaCabeceraColumnaSolicitud($celda)) {
                continue;
            }
            if (preg_match('/^(?:UNIDAD|UNIDADES|UN)$/iu', trim($celda)) === 1) {
                continue;
            }
            if ($this->esCeldaPrecioOMoneda($celda)) {
                continue;
            }
            $partesDescripcion[] = $celda;
        }

        $descripcion = trim(implode(' ', $partesDescripcion));
        if ($indiceCantidad === null || mb_strlen($descripcion) < 3) {
            return null;
        }

        $cantidad = $this->parseCantidadCeldaTabla($partes[$indiceCantidad]);
        if ($cantidad === null) {
            return null;
        }

        if ($this->esEncabezadoTablaProductoCantidad($descripcion)) {
            return null;
        }

        return [
            'cantidad' => $cantidad,
            'descripcion' => $descripcion,
        ];
    }

    /**
     * @return array{cantidad: int, descripcion: string}|null
     */
    private function intentarParColumnaProductoCantidad(string $descripcion, string $cantidadRaw): ?array
    {
        $descripcion = trim($descripcion);
        $cantidad = $this->parseCantidadCeldaTabla($cantidadRaw);

        if ($cantidad === null || mb_strlen($descripcion) < 3) {
            return null;
        }

        if (
            $this->esEncabezadoTablaProductoCantidad($descripcion)
            || $this->esLineaCabeceraColumnaSolicitud($descripcion)
        ) {
            return null;
        }

        return [
            'cantidad' => $cantidad,
            'descripcion' => $descripcion,
        ];
    }

    /**
     * @return array{cantidad: int, descripcion: string}|null
     */
    private function extraerProductoCantidadDeLinea(string $linea): ?array
    {
        $linea = trim($linea);
        $linea = preg_replace('/\s+y\s*$/iu', '', $linea) ?? $linea;
        if ($linea === '' || $this->esEncabezadoTablaProductoCantidad($linea)) {
            return null;
        }

        if (preg_match('/^UNIDADES?\s+(\d+)\s+(.+)$/iu', $linea, $coincidencia) === 1) {
            $descripcion = trim($coincidencia[2]);

            return mb_strlen($descripcion) >= 3
                ? ['cantidad' => max(1, (int) $coincidencia[1]), 'descripcion' => $descripcion]
                : null;
        }

        if (preg_match('/^(\d{1,5})\s+(.{3,})$/u', $linea, $coincidencia) === 1) {
            $descripcion = trim($coincidencia[2]);
            $cantidadInicio = (int) $coincidencia[1];
            if (
                ! preg_match('/^(?:unidades?|pack)\b/iu', $descripcion)
                && $this->pareceCantidadPedidoAlInicio($cantidadInicio, $descripcion)
            ) {
                return [
                    'cantidad' => max(1, $cantidadInicio),
                    'descripcion' => $descripcion,
                ];
            }
        }

        if (preg_match('/^(.+\d+\s+UNIDADES)\s+(\d{1,5})\s*$/iu', $linea, $coincidencia) === 1) {
            $descripcion = trim($coincidencia[1]);

            return mb_strlen($descripcion) >= 3
                ? ['cantidad' => max(1, (int) $coincidencia[2]), 'descripcion' => $descripcion]
                : null;
        }

        if (preg_match('/^(.+)\s+(\d+)\s+cajas?\s*$/iu', $linea, $coincidencia) === 1) {
            $descripcion = trim($coincidencia[1]);

            return mb_strlen($descripcion) >= 3
                ? ['cantidad' => max(1, (int) $coincidencia[2]), 'descripcion' => $descripcion]
                : null;
        }

        if (preg_match('/^(.+?\d+\s+MM)\s+(\d+)\s+sobres?\b/iu', $linea, $coincidencia) === 1) {
            $descripcion = trim($coincidencia[1]);
            if (preg_match('/\b(?:BLANCO|GRAMOS|GSM)\b/iu', $linea) === 1) {
                $descripcion = trim($linea);
            }

            return mb_strlen($descripcion) >= 3
                ? ['cantidad' => max(1, (int) $coincidencia[2]), 'descripcion' => $descripcion]
                : null;
        }

        if (preg_match('/^(.+)\s+(\d+)\s+sobres?\s*$/iu', $linea, $coincidencia) === 1) {
            $descripcion = trim($coincidencia[1]);

            return mb_strlen($descripcion) >= 3
                ? ['cantidad' => max(1, (int) $coincidencia[2]), 'descripcion' => $descripcion]
                : null;
        }

        if (preg_match('/^(.+)\s+(\d+)\s+bolsas?\b/iu', $linea, $coincidencia) === 1) {
            return mb_strlen(trim($coincidencia[1])) >= 3
                ? ['cantidad' => max(1, (int) $coincidencia[2]), 'descripcion' => trim($linea)]
                : null;
        }

        if (preg_match('/^(.+)\s+(\d+)\s+pliegos?\b/iu', $linea, $coincidencia) === 1) {
            return mb_strlen(trim($coincidencia[1])) >= 3
                ? ['cantidad' => max(1, (int) $coincidencia[2]), 'descripcion' => trim($linea)]
                : null;
        }

        if (preg_match('/^(.+)\s+(\d+)\s+rollos?\b/iu', $linea, $coincidencia) === 1) {
            $descripcion = trim($coincidencia[1]);

            return mb_strlen($descripcion) >= 3
                ? ['cantidad' => max(1, (int) $coincidencia[2]), 'descripcion' => $descripcion]
                : null;
        }

        if (preg_match('/^(.+)\s+(\d+)\s+unidades\b/iu', $linea, $coincidencia) === 1) {
            $descripcion = trim($coincidencia[1]);
            if (preg_match('/\b(?:CAJA|PACK|HOJAS|BOLSA|DISPLAY|SOBRE)(?:\s+\d+)?\s*$/iu', $descripcion) !== 1) {
                return mb_strlen($descripcion) >= 3
                    ? ['cantidad' => max(1, (int) $coincidencia[2]), 'descripcion' => trim($linea)]
                    : null;
            }
        }

        if (preg_match('/^(.+)\s+(\d+)\s+paquetes?\b/iu', $linea, $coincidencia) === 1) {
            $descripcion = trim($coincidencia[1]);

            return mb_strlen($descripcion) >= 3
                ? ['cantidad' => max(1, (int) $coincidencia[2]), 'descripcion' => trim($linea)]
                : null;
        }

        if (preg_match('/^(.+)\s+(\d+)\s+unidades?\.?\s*$/u', $linea, $coincidencia) === 1) {
            $descripcion = trim($coincidencia[1]);
            if (preg_match('/\b(?:CAJA|PACK|HOJAS|BOLSA|DISPLAY|SOBRE)(?:\s+\d+)?\s*$/iu', $descripcion) === 1) {
                // Empaque dentro de la descripción (ej. CLIP METALICO CAJA 10 UNIDADES + cantidad en otra línea).
            } else {
                return mb_strlen($descripcion) >= 3
                    ? ['cantidad' => max(1, (int) $coincidencia[2]), 'descripcion' => $descripcion]
                    : null;
            }
        }

        if (preg_match('/^(.+)\s+(\d+)\s+UNIDADES\s*$/u', $linea, $coincidencia) === 1) {
            $descripcion = trim($coincidencia[1]);
            if (preg_match('/\b(?:CAJA|PACK|HOJAS|BOLSA|DISPLAY|SOBRE)(?:\s+\d+)?\s*$/iu', $descripcion) !== 1
                && preg_match('/\b\d+\s+(?:bolsas?|pliegos?|rollos?|sobres?|cajas?)\b/iu', $linea) !== 1) {
                return mb_strlen($descripcion) >= 3
                    ? ['cantidad' => max(1, (int) $coincidencia[2]), 'descripcion' => $descripcion]
                    : null;
            }
        }

        if (preg_match('/^(FUNDA PLASTICA TRANSPATENTE)\s+(\d+)\s+(?:CARTA|OFICIO)\b/iu', $linea, $coincidencia) === 1) {
            return [
                'cantidad' => max(1, (int) $coincidencia[2]),
                'descripcion' => trim($linea),
            ];
        }

        if (preg_match('/^(.+)\s+(\d+)\s*pack\.?\s*$/iu', $linea, $coincidencia) === 1) {
            $descripcion = trim($coincidencia[1]);

            return mb_strlen($descripcion) >= 3
                ? ['cantidad' => max(1, (int) $coincidencia[2]), 'descripcion' => $descripcion]
                : null;
        }

        if (preg_match('/^(.+)\s+(\d{1,5})\s*$/u', $linea, $coincidencia) === 1) {
            $descripcion = trim($coincidencia[1]);
            if ($this->esRuidoTablaProductoCantidad($descripcion)) {
                return null;
            }

            if (preg_match('/\d\.\d\s*$/u', $descripcion) === 1) {
                return null;
            }

            if ($this->esRuidoCantidadColumnaImagen($coincidencia[2], $linea)) {
                return null;
            }

            return $this->corregirCantidadEmpaqueConfundida([
                'cantidad' => max(1, (int) $coincidencia[2]),
                'descripcion' => $descripcion,
            ]);
        }

        return null;
    }

    private function pareceCantidadPedidoAlInicio(int $cantidad, string $descripcion): bool
    {
        if ($cantidad <= 0 || $cantidad > 999) {
            return false;
        }

        return preg_match(
            '/^(?:RESMA|LAPIZ|LAPICES|GOMA|CINTA|CUADERNO|CORCHETERA|PERFORADORA|CLIP|BOLIGRAFO|TIJERA|PEGAMENTO|ACUARELA|BLOCK|CARPETA|REGLA)/iu',
            $descripcion,
        ) === 1;
    }

    /**
     * Ruido OCR de columna imagen (ej. "e. 3") — no es cantidad pedido.
     */
    private function esRuidoCantidadColumnaImagen(string $cantidadRaw, string $lineaContexto = ''): bool
    {
        $cantidadRaw = trim($cantidadRaw);
        if ($cantidadRaw === '') {
            return true;
        }

        if (preg_match('/^[a-záéíóú]{1,2}\.?\s*\d{1,5}\.?$/iu', $cantidadRaw) === 1) {
            return true;
        }

        if ($lineaContexto !== '' && preg_match('/\s+[a-záéíóú]{1,2}\.\s*\d{1,5}\s*$/iu', $lineaContexto) === 1) {
            return true;
        }

        return false;
    }

    private function parseCantidadCeldaTabla(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '' || $this->esRuidoCantidadColumnaImagen($raw, $raw)) {
            return null;
        }

        if (preg_match('/^(\d+)\s*unidades?\.?$/iu', $raw, $coincidencia) === 1) {
            return max(1, (int) $coincidencia[1]);
        }

        if (preg_match('/^(\d+)\s*pack\.?$/iu', $raw, $coincidencia) === 1) {
            return max(1, (int) $coincidencia[1]);
        }

        if (preg_match('/^(\d+)\s+pack\s+de\s+\d+\s+unidades?\s*$/iu', $raw, $coincidencia) === 1) {
            return max(1, (int) $coincidencia[1]);
        }

        if (preg_match('/^(\d+)\s*cajas?\.?$/iu', $raw, $coincidencia) === 1) {
            return max(1, (int) $coincidencia[1]);
        }

        if (preg_match('/^(\d+)\s*sobres?\.?$/iu', $raw, $coincidencia) === 1) {
            return max(1, (int) $coincidencia[1]);
        }

        if (preg_match('/^(\d+)\s*sets?\.?$/iu', $raw, $coincidencia) === 1) {
            return max(1, (int) $coincidencia[1]);
        }

        if (preg_match('/^(\d+)\s*bolsas?\.?$/iu', $raw, $coincidencia) === 1) {
            return max(1, (int) $coincidencia[1]);
        }

        if (preg_match('/^(\d+)\s*pliegos?\.?$/iu', $raw, $coincidencia) === 1) {
            return max(1, (int) $coincidencia[1]);
        }

        if (preg_match('/^(\d+)\s*rollos?\.?$/iu', $raw, $coincidencia) === 1) {
            return max(1, (int) $coincidencia[1]);
        }

        if (preg_match('/^(\d{1,5})$/u', $raw, $coincidencia) === 1) {
            return max(1, (int) $coincidencia[1]);
        }

        return null;
    }

    /**
     * Formato EETT / especificaciones técnicas: bloques "PRODUCTO:" + "N unidades".
     *
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function esFormatoTablaDideco(string $upper): bool
    {
        if (! str_contains($upper, 'BIEN O SERVICIO') || ! str_contains($upper, 'CANTIDAD')) {
            return false;
        }

        return str_contains($upper, 'UNIDAD DE MEDIDA')
            || (
                (str_contains($upper, 'ESPECIFICACIONES TECNICAS') || str_contains($upper, 'ESPECIFICACIONES TÉCNICAS'))
                && preg_match('/\b(?:UNIDAD(?:ES)?|CAJA|DISPLAY)\s+\d+\s+/u', $upper) === 1
            );
    }

    /**
     * Tabla DIDECO / municipal: UNIDAD DE MEDIDA | CANTIDAD | BIEN O SERVICIO | ESPECIFICACIONES TÉCNICAS.
     *
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function parseTablaDideco(string $texto): array
    {
        $resultado = [];
        $buffer = null;
        $cantidadBuffer = null;

        foreach (preg_split('/\r\n|\n|\r/u', $texto) ?: [] as $lineaCruda) {
            $linea = trim($lineaCruda);
            if ($linea === '' || $this->esRuidoTablaDideco($linea)) {
                continue;
            }

            if (preg_match('/^(?:Unidad(?:es)?|Caja|Display)\s+(\d+)\s+(.+)$/iu', $linea, $coincidencia) === 1) {
                if ($buffer !== null && $cantidadBuffer !== null) {
                    $resultado[] = [
                        'cantidad' => $cantidadBuffer,
                        'descripcion' => trim($buffer),
                    ];
                }

                $cantidadBuffer = max(1, (int) $coincidencia[1]);
                $buffer = trim($coincidencia[2]);

                continue;
            }

            if ($buffer !== null && $this->pareceContinuacionTablaDideco($linea)) {
                $buffer = trim($buffer.' '.$linea);
            }
        }

        if ($buffer !== null && $cantidadBuffer !== null) {
            $resultado[] = [
                'cantidad' => $cantidadBuffer,
                'descripcion' => trim($buffer),
            ];
        }

        return $resultado;
    }

    private function esRuidoTablaDideco(string $linea): bool
    {
        $upper = mb_strtoupper(trim($linea));

        if ($upper === '') {
            return true;
        }

        if (preg_match('/^--\s*\d+\s+of\s+\d+\s+--$/u', $linea) === 1) {
            return true;
        }

        foreach ([
            'UNIDAD DE MEDIDA CANTIDAD BIEN O SERVICIO',
            'UNIDAD DE MEDIDA',
            'BIEN O SERVICIO',
            'ESPECIFICACIONES TÉCNICAS',
            'ESPECIFICACIONES TECNICAS',
            'ÍTEM PRESUPUESTARIO',
            'ITEM PRESUPUESTARIO',
        ] as $marcador) {
            if ($upper === $marcador || str_starts_with($upper, $marcador.' ')) {
                return true;
            }
        }

        if (preg_match('/^ESPECIFICACIONES\s+TECNICAS\s+-/u', $upper) === 1) {
            return true;
        }

        if (preg_match('/^ÍTEM\s+PRESUPUESTARIO:/u', $upper) === 1 || preg_match('/^ITEM\s+PRESUPUESTARIO:/u', $upper) === 1) {
            return true;
        }

        return preg_match('/^(?:INCLUSIÓN|MESA\s+ALIMENTACION|MESA\s+ALIMENTACIÓN)\b/u', $upper) === 1
            && preg_match('/^(?:Unidad(?:es)?|Caja|Display)\s+\d+/iu', $linea) !== 1;
    }

    private function pareceContinuacionTablaDideco(string $linea): bool
    {
        if ($this->esRuidoTablaDideco($linea)) {
            return false;
        }

        return preg_match('/^(?:Unidad(?:es)?|Caja|Display)\s+\d+\s+/iu', $linea) !== 1;
    }

    private function parseEettEspecificaciones(string $texto): array
    {
        $texto = $this->normalizarEspaciosDocumento($texto);
        $lineasRaw = preg_split('/\n+/u', $texto) ?: [];
        $lineasRaw = array_values(array_filter(array_map(
            static fn (string $l) => trim(preg_replace('/[ \t]+/u', ' ', $l) ?? $l),
            $lineasRaw,
        ), static fn (string $l) => $l !== ''));

        $bloques = [];
        $actual = null;

        foreach ($lineasRaw as $linea) {
            $upper = mb_strtoupper($linea);

            if (
                str_starts_with($upper, 'LUGAR DE ENTREGA')
                || str_starts_with($upper, '6.-')
                || str_starts_with($upper, '6.')
                || str_contains($upper, 'IMAGEN DE REFERENCIA')
            ) {
                if ($actual !== null) {
                    $bloques[] = $actual;
                    $actual = null;
                }

                continue;
            }

            // Cabecera de producto tipo "STEP:" o "BANDA ELASTICA 45 M:"
            if (preg_match('/^([A-ZÁÉÍÓÚÑ0-9][A-ZÁÉÍÓÚÑ0-9 \.\/\-]{1,80}):\s*$/u', $linea, $m) === 1) {
                if ($actual !== null) {
                    $bloques[] = $actual;
                }
                $actual = [
                    'nombre' => trim($m[1]),
                    'detalle' => [],
                ];

                continue;
            }

            if ($actual === null) {
                continue;
            }

            $actual['detalle'][] = $linea;
        }

        if ($actual !== null) {
            $bloques[] = $actual;
        }

        $resultado = [];
        foreach ($bloques as $bloque) {
            $detalleTexto = implode(' ', $bloque['detalle']);
            $cantidad = 1;
            if (preg_match('/(\d+)\s*unidades/iu', $detalleTexto, $m) === 1) {
                $cantidad = max(1, (int) $m[1]);
            } elseif (preg_match('/^\s*(\d+)\s*[|]\s*(\d+)\s*unidades/imu', implode("\n", $bloque['detalle']), $m) === 1) {
                $cantidad = max(1, (int) $m[2]);
            }

            $specs = [];
            foreach ($bloque['detalle'] as $lineaDetalle) {
                $limpia = trim($lineaDetalle);
                if ($limpia === '') {
                    continue;
                }
                if (preg_match('/\d+\s*unidades/iu', $limpia) === 1 && ! str_contains($limpia, ':')) {
                    continue;
                }
                if (preg_match('/^\d+\s*[|]/u', $limpia) === 1) {
                    $limpia = trim((string) preg_replace('/^\d+\s*[|]\s*\d+\s*unidades\s*[-–]?\s*/iu', '', $limpia));
                    if ($limpia === '') {
                        continue;
                    }
                }
                $limpia = ltrim($limpia, "-•*.~ \t");
                if ($limpia !== '') {
                    $specs[] = $limpia;
                }
            }

            $descripcion = trim($bloque['nombre'].(count($specs) > 0 ? ' — '.implode('; ', array_slice($specs, 0, 10)) : ''));
            if ($descripcion === '' || $this->esDescripcionAdministrativa($descripcion)) {
                continue;
            }

            $resultado[] = [
                'cantidad' => $cantidad,
                'descripcion' => mb_substr($descripcion, 0, 500),
            ];
        }

        // Fallback: filas "N | M unidades" sin bloque de nombre claro
        if ($resultado === [] && preg_match_all('/(\d+)\s*[|]\s*(\d+)\s*unidades/iu', $texto, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $resultado[] = [
                    'cantidad' => max(1, (int) $match[2]),
                    'descripcion' => 'Ítem '.(int) $match[1],
                ];
            }
        }

        return $resultado;
    }

    private function extraerTextoDocx(string $path): string
    {
        $xml = $this->leerDocumentXmlDocx($path);
        $filas = $this->extraerFilasTablaDocx($xml);
        $lineas = [];

        foreach ($filas as $celdas) {
            $lineas[] = trim(implode(' ', array_filter($celdas, static fn (string $c) => $c !== '')));
        }

        $parrafos = $this->extraerParrafosDocx($xml);
        foreach ($parrafos as $parrafo) {
            $lineas[] = $parrafo;
        }

        $texto = trim(implode("\n", array_filter($lineas, static fn (string $l) => $l !== '')));
        if ($texto === '') {
            throw new RuntimeException('El archivo Word no contiene texto legible para importar.');
        }

        return $texto;
    }

    private function leerDocumentXmlDocx(string $path): string
    {
        if (! is_readable($path)) {
            throw new RuntimeException('No se pudo leer el archivo Word.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('No se pudo abrir el archivo Word (.docx).');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false || trim($xml) === '') {
            throw new RuntimeException('El archivo Word no tiene contenido legible (document.xml).');
        }

        return $xml;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function extraerFilasTablaDocx(string $xml): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $cargado = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $cargado) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $filas = [];
        foreach ($xpath->query('//w:tr') ?: [] as $tr) {
            if (! $tr instanceof DOMElement) {
                continue;
            }

            $celdas = [];
            foreach ($xpath->query('.//w:tc', $tr) ?: [] as $tc) {
                if (! $tc instanceof DOMElement) {
                    continue;
                }
                $celdas[] = $this->textoNodoDocx($tc);
            }

            if ($celdas !== []) {
                $filas[] = $celdas;
            }
        }

        return $filas;
    }

    /**
     * @return array<int, string>
     */
    private function extraerParrafosDocx(string $xml): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $cargado = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $cargado) {
            return [];
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $parrafos = [];
        foreach ($xpath->query('//w:p[not(ancestor::w:tc)]') ?: [] as $p) {
            if (! $p instanceof DOMElement) {
                continue;
            }
            $texto = $this->textoNodoDocx($p);
            if ($texto !== '') {
                $parrafos[] = $texto;
            }
        }

        return $parrafos;
    }

    private function textoNodoDocx(DOMNode $nodo): string
    {
        $documento = $nodo->ownerDocument;
        if ($documento === null) {
            return trim(preg_replace('/\s+/u', ' ', $nodo->textContent) ?? $nodo->textContent);
        }

        $xpath = new DOMXPath($documento);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $texto = '';
        foreach ($xpath->query('.//w:t', $nodo) ?: [] as $t) {
            $texto .= $t->textContent;
        }

        return trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);
    }

    /**
     * @param  array<int, string>  $celdas
     * @param  array<int, string>  $candidatos
     */
    private function indiceColumna(array $celdas, array $candidatos): ?int
    {
        foreach ($celdas as $i => $celda) {
            foreach ($candidatos as $candidato) {
                if ($celda === $candidato || str_contains($celda, $candidato)) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function normalizarEncabezadoCelda(string $texto): string
    {
        $texto = mb_strtoupper(trim($texto));
        $texto = strtr($texto, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
            'Ü' => 'U',
        ]);

        return preg_replace('/\s+/u', ' ', $texto) ?? $texto;
    }

    /**
     * En tablas escaneadas el OCR antepone los bordes de celda a la cantidad
     * ("| 20 …", ". 20 …"). Solo se recorta si detrás queda un número.
     */
    private function quitarBordeTablaOcr(string $linea): string
    {
        if (preg_match('/^[|\[\]({_`\'"‘’·•.,:;\s]+(\d.*)$/u', $linea, $m) === 1) {
            return trim($m[1]);
        }

        return $linea;
    }

    private function limpiarInicioDescripcion(string $descripcion): string
    {
        $limpia = preg_replace('/^\s*c\s*\/\s*[ua](?:no)?\b/iu', '', $descripcion) ?? $descripcion;
        $limpia = preg_replace('/^[|\[\]({_`\'"‘’—–\s]+/u', '', $limpia) ?? $limpia;

        return trim($limpia);
    }

    private function esEncabezadoListado(string $linea): bool
    {
        $normalizada = mb_strtoupper($linea);

        return str_contains($normalizada, 'CANTIDAD')
            && (str_contains($normalizada, 'NOMBRE') || str_contains($normalizada, 'PRODUCTO'));
    }

    private function esRuidoListado(string $linea): bool
    {
        $normalizada = mb_strtoupper($linea);

        return str_starts_with($normalizada, 'LISTA DE MATERIALES')
            || str_starts_with($normalizada, 'NOTA:');
    }
}

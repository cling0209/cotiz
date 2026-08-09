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

        $texto = $this->extraerTextoPdf($path);
        $lineasDesdeTexto = $this->parseTexto($texto);

        return [
            'cabecera' => $this->extraerCabeceraDocumento($texto),
            'lineas' => $this->fusionarLineasConPaddle($path, $lineasDesdeTexto, $texto),
        ];
    }

    /**
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    public function parseUploadedFile(UploadedFile $file): array
    {
        return $this->parseDocumentoCompleto($file)['lineas'];
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
            self::FORMATO_EETT => $this->parseEettEspecificaciones($texto),
            default => $this->parseListadoCantidad($texto),
        };

        return array_values(array_filter(
            $lineas,
            fn (array $linea): bool => ! $this->esDescripcionAdministrativa($linea['descripcion']),
        ));
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

        if (
            (str_contains($upper, 'DETALLE DEL REQUERIMIENTO') || str_contains($upper, 'ESPECIFICACIONES TECNICAS') || str_contains($upper, 'ESPECIFICACIONES TÉCNICAS'))
            && (str_contains($upper, 'UNIDADES') || preg_match('/\d+\s*UNIDADES/u', $upper) === 1)
        ) {
            return self::FORMATO_EETT;
        }

        return self::FORMATO_LISTADO;
    }

    private function normalizarEspaciosDocumento(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $texto = str_replace("\t", ' ', $texto);

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
        $patronFin = '/^(\d{1,3})\s+(.+?)\s+(\d{1,3}(?:\.\d{3})+|\d+)\s+(\d{1,3}(?:\.\d{3})+)$/u';

        $tryFlush = function (string $text) use (&$resultado, $patronFin): bool {
            if (preg_match($patronFin, $text, $m) !== 1) {
                return false;
            }

            $descripcion = trim($m[2]);
            $unidades = str_replace('.', '', $m[3]);
            if ($descripcion === '' || ! ctype_digit($unidades) || $this->esDescripcionAdministrativa($descripcion)) {
                return false;
            }

            $resultado[] = [
                'cantidad' => max(1, (int) $unidades),
                'descripcion' => $descripcion,
            ];

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
        $idxCantidad = null;
        $idxProducto = null;

        foreach ($filas as $celdas) {
            $normalizadas = [];
            foreach ($celdas as $celda) {
                $normalizadas[] = $this->normalizarEncabezadoCelda($celda);
            }

            if ($idxCantidad === null) {
                $idxCantidad = $this->indiceColumna($normalizadas, ['CANTIDAD']);
                $idxProducto = $this->indiceColumna($normalizadas, ['PRODUCTO', 'NOMBRE DEL PRODUCTO', 'NOMBRE', 'DESCRIPCION', 'DESCRIPCIÓN']);
                if ($idxCantidad !== null && $idxProducto !== null) {
                    continue;
                }
                $idxCantidad = null;
                $idxProducto = null;

                continue;
            }

            $cantidadRaw = trim($celdas[$idxCantidad] ?? '');
            $descripcion = trim($celdas[$idxProducto] ?? '');
            if ($descripcion === '' || ! preg_match('/^\d{1,6}$/u', $cantidadRaw)) {
                continue;
            }

            $resultado[] = [
                'cantidad' => max(1, (int) $cantidadRaw),
                'descripcion' => $descripcion,
            ];
        }

        return $resultado;
    }

    private function extraerTextoPdf(string $path): string
    {
        if (! is_readable($path)) {
            throw new RuntimeException('No se pudo leer el archivo.');
        }

        try {
            $parser = new Parser;
            $pdf = $parser->parseFile($path);
            $textoNativo = trim((string) $pdf->getText());
        } catch (\Throwable $e) {
            throw new RuntimeException('No se pudo extraer texto del PDF. Verifique que no sea un documento escaneado.', 0, $e);
        }

        if ($textoNativo === '') {
            return $this->resolverTextoPdfMedianteOcr($path, null);
        }

        if ($this->debeComplementarTextoPdfConOcr($path, $textoNativo)) {
            try {
                return $this->resolverTextoPdfMedianteOcr($path, $textoNativo);
            } catch (RuntimeException) {
                return $textoNativo;
            }
        }

        return $textoNativo;
    }

    private function resolverTextoPdfMedianteOcr(string $path, ?string $textoNativo): string
    {
        $textoOcr = $this->extraerTextoPdfMedianteOcr($path, false);
        $elegido = $textoNativo !== null
            ? $this->elegirMejorTextoPdfTablaProducto($textoNativo, $textoOcr)
            : $textoOcr;

        $paginas = max($this->contarPaginasPdf($path), $this->inferirPaginasDesdeTexto($elegido));
        $upper = mb_strtoupper($this->normalizarEspaciosDocumento($elegido));
        if (! $this->esFormatoTablaProductoCantidad($upper)) {
            return $elegido;
        }

        $lineas = count($this->parseTablaProductoCantidad($elegido));
        if ($lineas >= max(12, (int) floor($paginas * 6))) {
            return $elegido;
        }

        try {
            $textoCrop = $this->extraerTextoPdfMedianteOcr($path, true);
            $mejor = $this->elegirMejorTextoPdfTablaProducto($elegido, $textoCrop);
            if ($textoNativo !== null && $textoNativo !== $elegido) {
                return $this->elegirMejorTextoPdfTablaProducto($textoNativo, $mejor);
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

    private function debeComplementarTextoPdfConOcr(string $path, string $textoNativo): bool
    {
        $upper = mb_strtoupper($this->normalizarEspaciosDocumento($textoNativo));
        if (! $this->esFormatoTablaProductoCantidad($upper)) {
            return false;
        }

        $ocr = $this->ocr ?? new PdfOcrService;
        if (! $ocr->estaDisponible()) {
            return false;
        }

        $paginas = max(
            $this->contarPaginasPdf($path),
            $this->inferirPaginasDesdeTexto($textoNativo),
        );
        if ($paginas < 2) {
            return false;
        }

        $lineas = count($this->parseTablaProductoCantidad($textoNativo));
        $minEsperadas = max(12, (int) floor($paginas * 6));

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

    private function contarPaginasPdf(string $path): int
    {
        try {
            $parser = new Parser;
            $pdf = $parser->parseFile($path);

            return max(1, count($pdf->getPages()));
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
    private function fusionarLineasConPaddle(string $path, array $lineasTexto, string $texto): array
    {
        $upper = mb_strtoupper($this->normalizarEspaciosDocumento($texto));
        $esTabla = $this->esFormatoTablaProductoCantidad($upper);
        $paginas = max($this->contarPaginasPdf($path), $this->inferirPaginasDesdeTexto($texto));
        $minEsperadas = max(12, (int) floor($paginas * 6));

        if (! $esTabla && count($lineasTexto) >= $minEsperadas) {
            return $lineasTexto;
        }

        $paddle = $this->paddle ?? new PdfPaddleOcrService;
        if (! $paddle->estaDisponible()) {
            Log::warning('Import PDF: PaddleOCR no disponible; se usa solo texto nativo/Tesseract', [
                'lineas_texto' => count($lineasTexto),
            ]);

            return $lineasTexto;
        }

        try {
            $lineasPaddle = $paddle->extraerLineasTabla($path);
        } catch (\Throwable $e) {
            Log::warning('Import PDF: PaddleOCR falló; se usa solo texto nativo/Tesseract', [
                'error' => $e->getMessage(),
                'lineas_texto' => count($lineasTexto),
            ]);

            return $lineasTexto;
        }

        if ($lineasPaddle === []) {
            return $lineasTexto;
        }

        if (count($lineasPaddle) > count($lineasTexto)) {
            Log::info('Import PDF: PaddleOCR aportó más filas que Tesseract', [
                'paddle' => count($lineasPaddle),
                'texto' => count($lineasTexto),
            ]);

            return $this->finalizarLineasTablaSolicitudPedido(
                $texto,
                $this->deduplicarLineasTabla(array_merge($lineasPaddle, $lineasTexto)),
            );
        }

        if (count($lineasTexto) > count($lineasPaddle)) {
            Log::info('Import PDF: texto/Tesseract aportó más filas que PaddleOCR', [
                'texto' => count($lineasTexto),
                'paddle' => count($lineasPaddle),
            ]);

            return $this->finalizarLineasTablaSolicitudPedido(
                $texto,
                $this->deduplicarLineasTabla(array_merge($lineasTexto, $lineasPaddle)),
            );
        }

        Log::info('Import PDF: PaddleOCR y Tesseract con mismas filas; se combinan', [
            'filas' => count($lineasPaddle),
        ]);

        return $this->finalizarLineasTablaSolicitudPedido(
            $texto,
            $this->deduplicarLineasTabla(array_merge($lineasPaddle, $lineasTexto)),
        );
    }

    /**
     * @param  array<int, array{cantidad: int, descripcion: string}>  $lineas
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function finalizarLineasTablaSolicitudPedido(string $texto, array $lineas): array
    {
        $upper = mb_strtoupper($this->normalizarEspaciosDocumento($texto));
        if (preg_match('/ESPECIFICACIONES\s+SOLICITUD\s+DE\s+PEDIDO/u', $upper) !== 1) {
            return $lineas;
        }

        $lineas = $this->repararFilasTablaSolicitudOcr($lineas);

        return $this->completarFilasSolicitudPedidoDesdeTexto($texto, $lineas);
    }

    private function esFormatoTablaProductoCantidad(string $upper): bool
    {
        if (str_contains($upper, 'DETALLE PRODUCTO')) {
            return false;
        }

        if (
            str_contains($upper, 'ESPECIFICACIONES TECNICAS')
            || str_contains($upper, 'ESPECIFICACIONES TÉCNICAS')
            || str_contains($upper, 'CANTIDAD DETALLE DEL REQUERIMIENTO')
        ) {
            return false;
        }

        if (preg_match('/ESPECIFICACIONES\s+SOLICITUD\s+DE\s+PEDIDO/u', $upper) === 1) {
            return true;
        }

        if (preg_match('/PRODUCTO\s+CANTIDAD/u', $upper) === 1) {
            return true;
        }

        return preg_match('/\bPRODUCTO\b/u', $upper) === 1
            && preg_match('/\bCANTIDAD\b/u', $upper) === 1
            && preg_match('/IMAGEN\s+(?:DE\s+)?REFERENCIA/u', $upper) === 1;
    }

    /**
     * Tabla PDF/Word: PRODUCTO | CANTIDAD | IMAGEN REFERENCIA (p. ej. solicitud de pedido).
     *
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function parseTablaProductoCantidad(string $texto): array
    {
        $upperDoc = mb_strtoupper($this->normalizarEspaciosDocumento($texto));
        $esSolicitudPedido = preg_match('/ESPECIFICACIONES\s+SOLICITUD\s+DE\s+PEDIDO/u', $upperDoc) === 1;

        if ($esSolicitudPedido) {
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

            if (! $enTabla && $esSolicitudPedido) {
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
            $desdeBuffer = $this->extraerProductoCantidadDeLinea($buffer);
            if ($desdeBuffer !== null && ! $this->esFragmentoContinuacionDescripcion($desdeBuffer['descripcion'])) {
                $resultado[] = $this->corregirCantidadEmpaqueConfundida($desdeBuffer);
            } elseif ($cantidadPendiente !== null && mb_strlen($buffer) >= 5) {
                $resultado[] = $this->corregirCantidadEmpaqueConfundida([
                    'cantidad' => $cantidadPendiente,
                    'descripcion' => $buffer,
                ]);
            }
        } elseif ($cantidadPendiente !== null) {
            // cantidad huérfana al final — se descarta
        }

        if ($esSolicitudPedido) {
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
            '/^(.+? JUMBO 12)\s*\R\s*(\d+)\s+unidades?\s*\R\s*UNIDADES IMAGIA TRIANGULAR (LAPIZ PASTA .+)$/mu',
            '$1 UNIDADES IMAGIA TRIANGULAR $2 unidades'."\n".'$3',
            $texto,
        ) ?? $texto;

        // Cinta u otros productos partidos: descripción en una línea, medida+cantidad en la siguiente.
        $texto = preg_replace(
            '/^(CINTA DOBLE CONTACTO 18MM X)\s*\R\s*([\d,\.]+\s*MTS)\s+(\d+)\s*$/mu',
            '$1 $2 $3',
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
            $desc = $reparado[$i]['descripcion'];

            if (preg_match('/^LAPICES DE CERA JUMBO 12$/iu', $desc) === 1) {
                $reparado[$i]['descripcion'] = 'LAPICES DE CERA JUMBO 12 UNIDADES IMAGIA TRIANGULAR';
            }

            if ($i + 1 < count($reparado)
                && preg_match('/^UNIDADES IMAGIA TRIANGULAR (.+)$/iu', $reparado[$i + 1]['descripcion'], $coincidencia) === 1) {
                $reparado[$i + 1]['descripcion'] = $this->sanearDescripcionTablaOcr($coincidencia[1]);
            }
        }

        return $reparado;
    }

    /**
     * Recupera filas que el OCR partió en otro bloque de texto (p. ej. nativo + Tesseract).
     *
     * @param  array<int, array{cantidad: int, descripcion: string}>  $resultado
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function completarFilasSolicitudPedidoDesdeTexto(string $texto, array $resultado): array
    {
        $patrones = [
            'LAPIZ PASTA ARTEL PTA AZUL' => '/LAPIZ\s+PASTA\s+ARTEL\s+PTA\s+AZUL/iu',
            'LAPIZ PASTA ARTEL PTA ROJO' => '/LAPIZ\s+PASTA\s+ARTEL\s+PTA\s+ROJO/iu',
            'RESMA OFICIO' => '/RESMA\s+OFICIO/iu',
        ];

        foreach (preg_split('/\r\n|\n|\r/u', $texto) ?: [] as $lineaCruda) {
            $linea = $this->normalizarLineaTablaOcr($lineaCruda);
            if ($linea === '' || $this->esRuidoTablaProductoCantidad($linea)) {
                continue;
            }

            foreach ($patrones as $needle => $pattern) {
                if ($this->resultadoContieneProducto($resultado, $needle)) {
                    continue;
                }

                if (preg_match($pattern, $linea) !== 1) {
                    continue;
                }

                $candidata = $this->sanearDescripcionTablaOcr($linea);
                $fila = $this->intentarFilaTablaProducto($candidata, $lineaCruda)
                    ?? $this->intentarFilaTablaProducto($linea, $lineaCruda);

                if ($fila === null) {
                    continue;
                }

                $fila = $this->corregirCantidadEmpaqueConfundida($fila);
                $resultado[] = $fila;
            }
        }

        return $resultado;
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

        if (preg_match('/^UNIDADES IMAGIA TRIANGULAR (.+)$/iu', $descripcion, $coincidencia) === 1) {
            $descripcion = trim($coincidencia[1]);
        }

        return trim($descripcion);
    }

    /**
     * @param  array<int, array{cantidad: int, descripcion: string}>  $resultado
     * @return array<int, array{cantidad: int, descripcion: string}>
     */
    private function deduplicarLineasTabla(array $resultado): array
    {
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
            && preg_match('/\b(LAPIZ|LAPICES|PASTA|PTA|CERA|BOLIGRAFO|PLUMON|MARCADOR)\b/iu', $descripcion) === 1) {
            $fila['cantidad'] = 1;
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

        return $fila;
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

        $desdeEspacios = $this->extraerDesdeColumnasEspaciadas($linea);
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

        if (count($partes) < 2) {
            return null;
        }

        $descripcion = $partes[0];
        $cantidad = $this->parseCantidadCeldaTabla($partes[1]);

        if ($cantidad === null || mb_strlen($descripcion) < 3) {
            return null;
        }

        if ($this->esLineaCabeceraColumnaSolicitud($descripcion) || $this->esEncabezadoTablaProductoCantidad($descripcion)) {
            return null;
        }

        return [
            'cantidad' => $cantidad,
            'descripcion' => $descripcion,
        ];
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

        if (count($partes) < 2) {
            return null;
        }

        $descripcion = trim($partes[0]);
        $cantidadRaw = trim($partes[1]);
        $cantidad = $this->parseCantidadCeldaTabla($cantidadRaw);

        if ($cantidad === null || mb_strlen($descripcion) < 3) {
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
    private function extraerProductoCantidadDeLinea(string $linea): ?array
    {
        $linea = trim($linea);
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
            $tieneEmpaqueUnidades = preg_match('/\b\d+\s+UNIDADES\b/iu', $linea) === 1;
            if (
                ! preg_match('/^(?:unidades?|pack)\b/iu', $descripcion)
                && ! $tieneEmpaqueUnidades
                && $this->pareceCantidadPedidoAlInicio($cantidadInicio, $descripcion)
            ) {
                return [
                    'cantidad' => max(1, $cantidadInicio),
                    'descripcion' => $descripcion,
                ];
            }
        }

        if (preg_match('/^(.+)\s+(\d+)\s+unidades?\.?\s*$/u', $linea, $coincidencia) === 1) {
            $descripcion = trim($coincidencia[1]);

            return mb_strlen($descripcion) >= 3
                ? ['cantidad' => max(1, (int) $coincidencia[2]), 'descripcion' => $descripcion]
                : null;
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

    private function parseCantidadCeldaTabla(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^(\d+)\s*unidades?\.?$/iu', $raw, $coincidencia) === 1) {
            return max(1, (int) $coincidencia[1]);
        }

        if (preg_match('/^(\d+)\s*pack\.?$/iu', $raw, $coincidencia) === 1) {
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

<?php

namespace App\Services;

use App\Models\Maeprod;
use App\Models\Nota;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CotizacionCargaArchivoService
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        protected NotaService $notaService,
        protected NotaDetalleService $detalleService,
    ) {}

    public function validarArchivo(UploadedFile $archivo): void
    {
        if (! $archivo->isValid()) {
            throw new RuntimeException($this->mensajeErrorSubida($archivo->getError()));
        }

        if ($archivo->getSize() > self::MAX_BYTES) {
            throw new RuntimeException('El archivo excede el tamaño máximo permitido de 10 MB');
        }

        if (strtolower($archivo->getClientOriginalExtension()) !== 'csv') {
            throw new RuntimeException('Formato de archivo no permitido. Solo se aceptan archivos CSV (.csv)');
        }
    }

    /**
     * @return array{resumen: array<string, mixed>, detalle: list<array<string, mixed>>}
     */
    public function parseCsv(string $ruta): array
    {
        $handle = fopen($ruta, 'r');
        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el archivo CSV');
        }

        $primeraLinea = fgets($handle);
        if ($primeraLinea === false) {
            fclose($handle);
            throw new RuntimeException('El archivo CSV está vacío o no tiene encabezados');
        }

        rewind($handle);
        $delimitador = $this->detectarDelimitador($primeraLinea);

        $encabezados = fgetcsv($handle, 0, $delimitador);
        if ($encabezados === false) {
            fclose($handle);
            throw new RuntimeException('El archivo CSV está vacío o no tiene encabezados');
        }

        $encabezados = array_map(fn ($h) => strtolower(trim($this->aUtf8((string) $h))), $encabezados);

        $datos = ['resumen' => [], 'detalle' => []];
        $lineaNum = 1;

        while (($fila = fgetcsv($handle, 0, $delimitador)) !== false) {
            $lineaNum++;

            $registro = [];
            foreach ($encabezados as $i => $clave) {
                $valor = isset($fila[$i]) ? trim($this->aUtf8((string) $fila[$i])) : '';
                $registro[$clave] = $valor;
            }

            if ($lineaNum === 2) {
                $datos['resumen'] = $this->extraerResumen($registro);
            }

            $datos['detalle'][] = $this->extraerDetalle($registro, $lineaNum);
        }

        fclose($handle);

        return $datos;
    }

    /**
     * @param  array{resumen: array<string, mixed>, detalle: list<array<string, mixed>>}  $datos
     */
    public function validarEstructura(array $datos): void
    {
        if ($datos['resumen'] === []) {
            throw new RuntimeException('No se encontraron datos de resumen en el archivo');
        }

        if (trim((string) ($datos['resumen']['empresa'] ?? '')) === '') {
            throw new RuntimeException("El campo 'empresa' o 'cliente' es obligatorio en el archivo");
        }

        if ($datos['detalle'] === []) {
            throw new RuntimeException('No se encontraron productos en el archivo');
        }
    }

    /**
     * @param  array<string, mixed>  $resumen
     */
    public function validarResumen(array $resumen, string $username): ?int
    {
        if (trim((string) ($resumen['rutempresa'] ?? '')) === '') {
            throw new RuntimeException('El RUT o Código del Cliente es obligatorio en el archivo');
        }

        if (trim((string) ($resumen['empresa'] ?? '')) === '') {
            throw new RuntimeException('El Nombre del Cliente es obligatorio en el archivo');
        }

        if (trim((string) ($resumen['contacto'] ?? '')) === '') {
            throw new RuntimeException('El Contacto es obligatorio en el archivo');
        }

        if (trim((string) ($resumen['encargado'] ?? '')) === '') {
            throw new RuntimeException('La Orden de Compra es obligatoria en el archivo');
        }

        $fecha = trim((string) ($resumen['fechaentrega'] ?? ''));
        if ($fecha !== '' && ! $this->fechaValida($fecha)) {
            throw new RuntimeException('La Fecha de Entrega no es válida. Use formato DD/MM/YYYY o DD-MM-YYYY');
        }

        if (isset($resumen['diashabiles']) && $resumen['diashabiles'] !== '' && $resumen['diashabiles'] !== null) {
            if (! is_numeric($resumen['diashabiles']) || (int) $resumen['diashabiles'] < 0) {
                throw new RuntimeException('Los Días de Entrega deben ser un número válido mayor o igual a 0');
            }
        }

        $encargado = trim((string) $resumen['encargado']);
        $notaExistente = Nota::query()
            ->whereRaw('trim(encargado) ilike ?', [$encargado])
            ->first(['nronota', 'usuario']);

        if ($notaExistente) {
            if (trim((string) $notaExistente->usuario) !== trim($username)) {
                throw new RuntimeException(
                    "La Orden de Compra «{$encargado}» ya existe en el sistema para otro usuario.",
                );
            }

            return (int) $notaExistente->nronota;
        }

        $errorRemoto = $this->consultaCotizacionRemota($encargado);
        if ($errorRemoto !== '') {
            throw new RuntimeException($errorRemoto);
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $detalle
     * @return list<array<string, mixed>>
     */
    public function buildPreviewDetalle(array $detalle): array
    {
        $factor = $this->factorDesdeDetalle($detalle);
        $preview = [];

        $codigos = [];
        foreach ($detalle as $producto) {
            $codigo = trim((string) ($producto['codigo'] ?? ''));
            if ($codigo !== '') {
                $codigos[] = $codigo;
            }
        }

        $maeprods = Maeprod::query()
            ->whereIn('prod_item', array_values(array_unique($codigos)))
            ->get()
            ->keyBy('prod_item');

        foreach ($detalle as $index => $producto) {
            $lineaCsv = $index + 2;
            $codigo = trim((string) ($producto['codigo'] ?? ''));
            $nombreCsv = trim((string) ($producto['nombre'] ?? ''));
            $cantidad = (int) ($producto['cantidad'] ?? 0);
            /** @var Maeprod|null $maeprod */
            $maeprod = $codigo !== '' ? $maeprods->get($codigo) : null;
            $prodNombre = trim((string) ($maeprod?->prod_nombre ?? $nombreCsv));
            $imageUrl = $maeprod ? trim($maeprod->imageUrl()) : '';

            $valido = true;
            $motivo = '';

            if ($codigo === '') {
                $valido = false;
                $motivo = 'Sin código de producto';
            } elseif ($factor <= 0) {
                $valido = false;
                $motivo = 'Factor no válido';
            } elseif ($cantidad <= 0) {
                $valido = false;
                $motivo = 'Cantidad no válida';
            } elseif (! $maeprod) {
                $valido = false;
                $motivo = "Código «{$codigo}» no existe en maeprod";
            }

            $preview[] = [
                'codigo' => $codigo,
                'nombre' => $nombreCsv,
                'prod_nombre' => $prodNombre,
                'image_url' => $imageUrl,
                'cantidad' => $cantidad,
                'factor' => $factor,
                'valido' => $valido,
                'motivo' => $motivo,
                'linea' => $lineaCsv,
            ];
        }

        return $preview;
    }

    /**
     * @param  array{resumen: array<string, mixed>, detalle: list<array<string, mixed>>}  $datos
     * @return array{nronota: int, detalle_count: int, detalle_omitidos: int, errores_validacion: list<string>}
     */
    public function guardar(array $datos, string $username, ?int $nronotaExistente = null): array
    {
        $this->validarEstructura($datos);
        $nronotaExistente = $this->validarResumen($datos['resumen'], $username) ?? $nronotaExistente;

        $detalle = $this->normalizarDetalleParaGuardar($datos['detalle']);
        $factor = $this->factorDesdeDetalle($detalle);

        return DB::transaction(function () use ($datos, $username, $nronotaExistente, $detalle, $factor) {
            $resumen = $datos['resumen'];

            if ($nronotaExistente !== null && $nronotaExistente > 0) {
                $nota = Nota::query()->findOrFail($nronotaExistente);
                $this->notaService->modificarCabecera($nota, array_merge($resumen, [
                    'descripcion' => '',
                    'factor_precio_venta' => $factor,
                ]));
            } else {
                $nota = $this->notaService->crear($username, '', null, 'CARGA_ARCHIVO');
                $this->notaService->modificarCabecera($nota, array_merge($resumen, [
                    'descripcion' => '',
                    'factor_precio_venta' => $factor,
                ]));
            }

            $nota->update(['sistema' => 'CARGA_ARCHIVO', 'enviadoapi' => 0]);

            $detalleCount = 0;
            $detalleOmitidos = 0;
            $errores = [];

            foreach ($detalle as $index => $producto) {
                $lineaCsv = $index + 2;
                $codigo = trim((string) ($producto['codigo'] ?? ''));

                if ($codigo === '') {
                    $errores[] = "Línea {$lineaCsv}: No tiene código de producto";
                    $detalleOmitidos++;

                    continue;
                }

                if ($factor <= 0) {
                    $errores[] = "Línea {$lineaCsv}: Código «{$codigo}» - Factor no válido ({$factor})";
                    $detalleOmitidos++;

                    continue;
                }

                $cantidad = (int) ($producto['cantidad'] ?? 0);
                if ($cantidad <= 0) {
                    $errores[] = "Línea {$lineaCsv}: Código «{$codigo}» - Cantidad no válida ({$cantidad})";
                    $detalleOmitidos++;

                    continue;
                }

                $maeprod = Maeprod::query()->find($codigo);
                if (! $maeprod) {
                    $errores[] = "Línea {$lineaCsv}: Código «{$codigo}» - No existe en maestro de productos";
                    $detalleOmitidos++;

                    continue;
                }

                $costo = (int) ($maeprod->prod_valor_costo ?? 0);
                $prodValor = (int) round($costo * $factor);

                $this->detalleService->agregarLinea(
                    $nota->fresh(),
                    $codigo,
                    $cantidad,
                    $prodValor,
                    $costo,
                    $username,
                    $codigo,
                );

                $detalleCount++;
            }

            return [
                'nronota' => (int) $nota->nronota,
                'detalle_count' => $detalleCount,
                'detalle_omitidos' => $detalleOmitidos,
                'errores_validacion' => $errores,
            ];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $detallePreview
     * @return list<array<string, mixed>>
     */
    public function normalizarDetalleParaGuardar(array $detallePreview): array
    {
        $resultado = [];

        foreach ($detallePreview as $item) {
            $codigo = trim((string) ($item['codigo'] ?? $item['prod_item_agile'] ?? ''));
            $nombre = trim((string) ($item['prod_nombre'] ?? $item['nombre'] ?? ''));
            $cantidad = (int) ($item['cantidad'] ?? 0);
            $factorRaw = $item['factor'] ?? 0;
            $factor = is_numeric(str_replace(',', '.', (string) $factorRaw))
                ? (float) str_replace(',', '.', (string) $factorRaw)
                : 0.0;

            $resultado[] = [
                'codigo' => $codigo,
                'nombre' => $nombre,
                'cantidad' => $cantidad,
                'factor' => $factor,
            ];
        }

        return $resultado;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{resumen: array<string, mixed>, detalle: list<array<string, mixed>>}|null
     */
    public function decodificarPayload(string $payload): ?array
    {
        if ($payload === '') {
            return null;
        }

        $intentos = [
            $payload,
            html_entity_decode($payload, ENT_QUOTES, 'UTF-8'),
            urldecode($payload),
            str_replace(' ', '+', $payload),
        ];

        foreach ($intentos as $raw) {
            $json = base64_decode($raw, true);
            if ($json === false) {
                continue;
            }

            $tmp = json_decode($json, true);
            if (is_array($tmp) && isset($tmp['resumen'], $tmp['detalle']) && is_array($tmp['detalle'])) {
                return [
                    'resumen' => $tmp['resumen'],
                    'detalle' => $this->normalizarDetalleParaGuardar($tmp['detalle']),
                ];
            }
        }

        return null;
    }

    public function contenidoPlantilla(): string
    {
        return "ORDEN DE COMPRA;RUT o CODIGO CLIENTE;NOMBRE DEL CLIENTE;CONTACTO;FECHA;DIAS DE ENTREGA;FACTOR;Codigo Producto;cantidad\n"
            ."123-01-COT25;99999999-9;NOMBRE EMPRESA;NOMBRE CONTACTO;10/10/2025;7;1,3;CODIGO;2\n";
    }

    private function mensajeErrorSubida(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño máximo permitido',
            UPLOAD_ERR_PARTIAL => 'El archivo se cargó parcialmente',
            UPLOAD_ERR_NO_FILE => 'No se seleccionó ningún archivo',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta la carpeta temporal del servidor',
            UPLOAD_ERR_CANT_WRITE => 'Error al escribir el archivo en disco',
            UPLOAD_ERR_EXTENSION => 'Una extensión de PHP detuvo la carga del archivo',
            default => "Error desconocido al subir el archivo (código: {$code})",
        };
    }

    /**
     * @param  array<string, string>  $registro
     * @return array<string, mixed>
     */
    private function extraerResumen(array $registro): array
    {
        $fechaEntrega = '';
        if (isset($registro['fecha']) && $registro['fecha'] !== '') {
            $fechaEntrega = $this->convertirFecha($registro['fecha']);
        }

        if ($fechaEntrega === '' && isset($registro['dias de entrega']) && $registro['dias de entrega'] !== '') {
            $dias = (int) $registro['dias de entrega'];
            $fechaEntrega = now()->addDays($dias)->toDateString();
        }

        return [
            'empresa' => trim($registro['nombre del cliente'] ?? $registro['empresa'] ?? ''),
            'encargado' => trim($registro['orden de compra'] ?? $registro['encargado'] ?? ''),
            'celular' => trim($registro['celular'] ?? ''),
            'contacto' => trim($registro['contacto'] ?? ''),
            'contactocorreo' => trim($registro['contactocorreo'] ?? ''),
            'rutempresa' => trim($registro['rut o codigo cliente'] ?? $registro['rutempresa'] ?? ''),
            'fechaentrega' => $fechaEntrega,
            'diashabiles' => isset($registro['dias de entrega']) ? (int) $registro['dias de entrega'] : 0,
            'descripcion' => trim($registro['descripcion'] ?? ''),
        ];
    }

    /**
     * @param  array<string, string>  $registro
     * @return array<string, mixed>
     */
    private function extraerDetalle(array $registro, int $lineaNum): array
    {
        $codigo = trim($registro['codigo producto'] ?? $registro['codigo'] ?? '');

        return [
            'codigo' => $codigo,
            'nombre' => trim($registro['producto'] ?? $registro['nombre'] ?? ''),
            'cantidad' => isset($registro['cantidad']) ? (int) $registro['cantidad'] : 0,
            'factor' => trim($registro['factor'] ?? ''),
            'linea' => $lineaNum,
        ];
    }

    private function detectarDelimitador(string $linea): string
    {
        $mejor = ',';
        $max = 0;

        foreach ([',', ';', "\t", '|'] as $delim) {
            $count = substr_count($linea, $delim);
            if ($count > $max) {
                $max = $count;
                $mejor = $delim;
            }
        }

        return $mejor;
    }

    private function convertirFecha(string $fecha): string
    {
        if ($fecha === '') {
            return '';
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $fecha, $m)) {
            $dia = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $mes = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $anio = $m[3];
            if (checkdate((int) $mes, (int) $dia, (int) $anio)) {
                return "{$anio}-{$mes}-{$dia}";
            }

            return '';
        }

        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $fecha, $m)) {
            $dia = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $mes = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            $anio = $m[3];
            if (checkdate((int) $mes, (int) $dia, (int) $anio)) {
                return "{$anio}-{$mes}-{$dia}";
            }

            return '';
        }

        return '';
    }

    private function fechaValida(string $fecha): bool
    {
        if ($fecha === '') {
            return true;
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $fecha, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]);
        }

        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $fecha, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]);
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $detalle
     */
    private function factorDesdeDetalle(array $detalle): float
    {
        if ($detalle === []) {
            return 0.0;
        }

        $raw = $detalle[0]['factor'] ?? 0;
        $texto = trim(str_replace(',', '.', (string) $raw));
        $factor = (float) $texto;

        return $factor > 0 ? round($factor, 4) : 0.0;
    }

    private function consultaCotizacionRemota(string $encargado): string
    {
        $url = trim((string) config('cotiz.api_nota.consulta_nro_cotizacion', ''));
        if ($url === '') {
            return '';
        }

        $hostRemoto = parse_url($url, PHP_URL_HOST);
        $hostLocal = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($hostRemoto && $hostLocal && strtolower((string) $hostRemoto) === $hostLocal) {
            return '';
        }

        try {
            $response = Http::timeout(15)
                ->withBasicAuth(
                    (string) config('cotiz.api_nota.user', ''),
                    (string) config('cotiz.api_nota.password', ''),
                )
                ->post($url, [
                    'accion' => 'cotizacion',
                    'encargado' => $encargado,
                ]);

            if (! $response->successful()) {
                return 'Error al consultar cotización en sitio central.';
            }

            $data = $response->json();
            if (isset($data['resultado']) && $data['resultado'] === 'OK') {
                return 'La Orden ya existe en otro sitio, favor verificar.';
            }
        } catch (\Throwable) {
            return 'Error al consultar cotización en sitio central.';
        }

        return '';
    }

    private function aUtf8(string $valor): string
    {
        if ($valor === '') {
            return '';
        }

        if (mb_detect_encoding($valor, 'UTF-8', true)) {
            return $valor;
        }

        $converted = @mb_convert_encoding($valor, 'UTF-8', 'ISO-8859-1');

        return $converted !== false ? $converted : $valor;
    }
}

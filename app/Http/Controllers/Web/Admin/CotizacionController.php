<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Models\User;
use App\Services\CompraAgilImportService;
use App\Services\NotaDetalleService;
use App\Services\NotaService;
use RuntimeException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CotizacionController extends Controller
{
    public function __construct(
        protected NotaService $notaService,
        protected NotaDetalleService $detalleService,
        protected CompraAgilImportService $compraAgilImport,
    ) {}

    public function create(Request $request): RedirectResponse
    {
        $usuario = $request->user()->username;

        if ($pendiente = $this->notaService->pendienteSinNumeroCotizacion($usuario)) {
            return redirect()
                ->route('admin.cotizaciones.edit', $pendiente->nronota)
                ->with('error', 'Complete el número de cotización de la nota #'.$pendiente->nronota.' antes de crear otra.');
        }

        $nota = $this->notaService->crear($usuario);

        if (! Nota::query()->whereKey($nota->nronota)->exists()) {
            return redirect()
                ->route('admin.cotizaciones.index')
                ->with('error', 'No se pudo crear la cotización. Intente nuevamente o contacte al administrador.');
        }

        return redirect()->route('admin.cotizaciones.edit', $nota->nronota)
            ->with('info', 'Ingrese el número de cotización para continuar.');
    }

    public function retomar(Request $request): RedirectResponse
    {
        $nota = $this->notaService->obtenerUltima($request->user()->username);

        if (! $nota) {
            return redirect()->route('admin.cotizaciones.create')
                ->with('error', 'No hay cotizaciones anteriores. Se creará una nueva.');
        }

        return redirect()->route('admin.cotizaciones.edit', $nota->nronota);
    }

    public function edit(Request $request, int $nronota): View|RedirectResponse
    {
        $nota = Nota::query()->with('detalle.producto')->find($nronota);

        if (! $nota) {
            return $this->notaNoEncontrada($nronota);
        }

        if (! $this->puedeVer($request, $nota)) {
            abort(403);
        }

        $lineas = $this->detalleService->lineasDeNota($nota);
        $hayPrecioAntiguo = $lineas->contains(fn ($row) => $row['prod_valor_fecha_antigua']);

        return view('admin.cotizaciones.form', [
            'nota' => $nota,
            'lineas' => $lineas,
            'total' => $lineas->sum(fn ($row) => $row['total']),
            'resumenLineas' => $this->detalleService->resumenLineasNota($nota),
            'hayPrecioAntiguo' => $hayPrecioAntiguo,
            'umbralPrecioMeses' => config('cotiz.prod_valor_fecha_meses'),
            'requiereNumeroCotizacion' => $nota->requiereNumeroCotizacion(),
            'desdeAdjudicadas' => $request->query('from') === 'adjudicadas',
            'mostrarSoftland' => $request->user()->isSuperAdmin(),
        ]);
    }

    public function update(Request $request, int $nronota): RedirectResponse
    {
        $nota = Nota::query()->find($nronota);

        if (! $nota) {
            return $this->notaNoEncontrada($nronota);
        }

        if (! $this->puedeVer($request, $nota)) {
            abort(403);
        }

        $accion = $request->string('accion')->toString();

        if ($accion === 'aplicar_factor') {
            if ($error = $this->notaService->validarNumeroCotizacion($nota)) {
                return back()->with('error', $error);
            }

            $factor = $this->notaService->parseFactorPrecioVenta($request->input('factor_precio_venta'));

            if ($factor === null) {
                return back()->withInput()->withErrors([
                    'factor_precio_venta' => 'El factor debe ser un número positivo con hasta 2 decimales (ej.: 1,30).',
                ]);
            }

            $result = $this->detalleService->aplicarFactorPrecioVenta(
                $nota,
                $factor,
                $request->user()->username,
            );

            $factorFmt = number_format($result['factor'], 2, ',', '');

            return $this->redirectTrasGuardar(
                $request,
                $nronota,
                'Se actualizaron '.$result['ok'].' de '.$result['total'].' filas. Factor guardado ('.$factorFmt.').',
            );
        }

        $input = $request->all();
        if (array_key_exists('factor_precio_venta', $input) && trim((string) $input['factor_precio_venta']) !== '') {
            $factor = $this->notaService->parseFactorPrecioVenta($input['factor_precio_venta']);
            if ($factor === null) {
                return back()->withInput()->withErrors([
                    'factor_precio_venta' => 'El factor debe ser un número positivo con hasta 2 decimales (ej.: 1,30).',
                ]);
            }
            $request->merge(['factor_precio_venta' => $factor]);
        }

        $lineasJson = $this->lineasDesdeJson($request);
        if ($lineasJson !== null) {
            $request->merge(['lineas' => $lineasJson]);
        }

        $datos = $request->validate(array_merge($this->reglasCabecera(), [
            'lineas_json' => ['nullable', 'string', 'max:500000'],
            'lineas' => ['nullable', 'array'],
            'lineas.*.prod_item' => ['required_with:lineas', 'string', 'max:50'],
            'lineas.*.orden' => ['required_with:lineas', 'integer'],
            'lineas.*.cantidad' => ['nullable', 'integer', 'min:1'],
            'lineas.*.prod_valor' => ['nullable', 'integer', 'min:0'],
            'lineas.*.prod_valor_costo' => ['nullable', 'integer', 'min:0'],
            'lineas.*.prod_item_softland' => ['nullable', 'string', 'max:20'],
        ]));

        if ($error = $this->notaService->validarNumeroCotizacionDisponible($nota, $datos['encargado'], false, true)) {
            return back()->withInput()->withErrors(['encargado' => $error]);
        }

        $lineas = $datos['lineas'] ?? [];
        unset($datos['lineas']);

        if ($request->user()->isEjecutivo()) {
            foreach ($lineas as &$linea) {
                unset($linea['prod_item_softland']);
            }
            unset($linea);
        }

        $eraSinNumero = $nota->requiereNumeroCotizacion();

        $this->notaService->modificarCabecera($nota, $datos);

        if ($lineas !== []) {
            $this->detalleService->guardarLineas($nota->fresh(), $lineas, $request->user()->username);
        }

        $mensaje = $eraSinNumero
            ? 'Número de cotización guardado. Ya puede agregar productos.'
            : 'Cotización guardada.';

        return $this->redirectTrasGuardar($request, $nronota, $mensaje);
    }

    public function guardarCabecera(Request $request, int $nronota): JsonResponse
    {
        $nota = Nota::query()->find($nronota);

        if (! $nota) {
            return response()->json([
                'error' => "La cotización #{$nronota} no existe.",
            ], 404);
        }

        if (! $this->puedeVer($request, $nota)) {
            abort(403);
        }

        if (array_key_exists('factor_precio_venta', $request->all())
            && trim((string) $request->input('factor_precio_venta')) !== '') {
            $factor = $this->notaService->parseFactorPrecioVenta($request->input('factor_precio_venta'));
            if ($factor === null) {
                return response()->json([
                    'error' => 'El factor debe ser un número positivo con hasta 2 decimales (ej.: 1,30).',
                    'errors' => ['factor_precio_venta' => ['El factor debe ser un número positivo con hasta 2 decimales (ej.: 1,30).']],
                ], 422);
            }
            $request->merge(['factor_precio_venta' => $factor]);
        }

        $datos = $request->validate($this->reglasCabecera());

        if ($error = $this->notaService->validarNumeroCotizacionDisponible($nota, $datos['encargado'], false, true)) {
            return response()->json([
                'error' => $error,
                'errors' => ['encargado' => [$error]],
            ], 422);
        }

        $eraSinNumero = $nota->requiereNumeroCotizacion();

        $this->notaService->modificarCabecera($nota, $datos);

        $mensaje = $eraSinNumero
            ? 'Número de cotización guardado. Ya puede agregar productos.'
            : 'Cotización guardada.';

        return response()->json([
            'ok' => true,
            'mensaje' => $mensaje,
            'era_sin_numero' => $eraSinNumero,
        ]);
    }

    public function guardarLineasLote(Request $request, int $nronota): JsonResponse
    {
        $nota = Nota::query()->find($nronota);

        if (! $nota) {
            return response()->json([
                'error' => "La cotización #{$nronota} no existe.",
            ], 404);
        }

        if (! $this->puedeVer($request, $nota)) {
            abort(403);
        }

        if ($error = $this->notaService->validarNumeroCotizacion($nota)) {
            return response()->json(['error' => $error], 422);
        }

        $datos = $request->validate($this->reglasLineasLote());

        $lineas = $datos['lineas'];

        if ($request->user()->isEjecutivo()) {
            foreach ($lineas as &$linea) {
                unset($linea['prod_item_softland']);
            }
            unset($linea);
        }

        try {
            $this->detalleService->guardarLineas($nota->fresh(), $lineas, $request->user()->username);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'guardadas' => count($lineas),
        ]);
    }

    public function aplicarFactor(Request $request, int $nronota): JsonResponse
    {
        $nota = Nota::query()->findOrFail($nronota);

        if (! $this->puedeVer($request, $nota)) {
            abort(403);
        }

        if ($error = $this->notaService->validarNumeroCotizacion($nota)) {
            return response()->json(['error' => $error], 422);
        }

        $factor = $this->notaService->parseFactorPrecioVenta($request->input('factor_precio_venta'));
        if ($factor === null) {
            return response()->json([
                'error' => 'El factor debe ser un número positivo con hasta 2 decimales (ej.: 1,30).',
            ], 422);
        }

        $result = $this->detalleService->aplicarFactorPrecioVenta(
            $nota,
            $factor,
            $request->user()->username,
        );

        return response()->json([
            'ok' => true,
            'factor_precio_venta_fmt' => number_format($result['factor'], 2, ',', ''),
            'ok_count' => $result['ok'],
            'total' => $result['total'],
            'lineas' => $result['lineas'],
        ]);
    }

    public function agregarLinea(Request $request, int $nronota): RedirectResponse|JsonResponse
    {
        $nota = Nota::query()->findOrFail($nronota);

        if (! $this->puedeVer($request, $nota)) {
            abort(403);
        }

        if ($respuesta = $this->rechazarSinNumeroCotizacion($request, $nota)) {
            return $respuesta;
        }

        $datos = $request->validate([
            'prod_item' => ['required', 'string', 'max:50'],
            'cantidad' => ['required', 'integer', 'min:1'],
            'prod_valor' => ['required', 'integer', 'min:0'],
            'prod_valor_costo' => ['nullable', 'integer', 'min:0'],
        ]);

        $producto = Maeprod::query()->find($datos['prod_item']);
        if (! $producto) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Producto no encontrado.'], 422);
            }

            return back()->with('error', 'Producto no encontrado.');
        }

        $detalle = $this->detalleService->agregarLinea(
            $nota,
            $datos['prod_item'],
            (int) $datos['cantidad'],
            (int) $datos['prod_valor'],
            isset($datos['prod_valor_costo']) ? (int) $datos['prod_valor_costo'] : null,
            $request->user()->username,
        );

        if ($request->expectsJson()) {
            $totalLineas = NotaDetalle::query()
                ->where('nronota', $nota->nronota)
                ->count();
            $idx = max(0, $totalLineas - 1);
            $row = $this->detalleService->filaLineaParaFormulario($nota, $detalle);

            return response()->json([
                'ok' => true,
                'idx' => $idx,
                'orden' => $detalle->orden,
                'prod_item' => (string) $detalle->prod_item,
                'prod_nombre' => $row['prod_nombre'],
                'image_url' => $row['image_url'] ?? '',
                'html' => view('admin.cotizaciones.partials.linea-detalle-row', [
                    'idx' => $idx,
                    'row' => $row,
                    'isFirst' => $idx === 0,
                    'isLast' => $idx === $totalLineas - 1,
                    'totalLineas' => $totalLineas,
                    'mostrarSoftland' => $request->user()->isSuperAdmin(),
                ])->render(),
                'delete_form_html' => view('admin.cotizaciones.partials.linea-detalle-delete-form', [
                    'nota' => $nota,
                    'row' => $row,
                ])->render(),
                'resumen' => $this->detalleService->resumenLineasNota($nota),
            ]);
        }

        return back()->with('success', 'Línea agregada.');
    }

    public function eliminarLinea(Request $request, int $nronota): RedirectResponse|JsonResponse
    {
        $nota = Nota::query()->findOrFail($nronota);

        if (! $this->puedeVer($request, $nota)) {
            abort(403);
        }

        if ($respuesta = $this->rechazarSinNumeroCotizacion($request, $nota)) {
            return $respuesta;
        }

        $datos = $request->validate([
            'prod_item' => ['nullable', 'string'],
            'orden' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $this->detalleService->eliminarLinea(
                $nota,
                (int) $datos['orden'],
                $datos['prod_item'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            if ($request->expectsJson()) {
                return response()->json(['error' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'resumen' => $this->detalleService->resumenLineasNota($nota),
                'lineas' => $this->detalleService->lineasOrdenJson($nota),
            ]);
        }

        return back()->with('success', 'Línea eliminada.');
    }

    public function cambiarOrdenLinea(Request $request, int $nronota): JsonResponse
    {
        $nota = Nota::query()->findOrFail($nronota);

        if (! $this->puedeVer($request, $nota)) {
            abort(403);
        }

        if ($respuesta = $this->rechazarSinNumeroCotizacion($request, $nota)) {
            return $respuesta;
        }

        $datos = $request->validate([
            'prod_item' => ['required', 'string', 'max:50'],
            'orden' => ['required', 'integer', 'min:1'],
            'direccion' => ['nullable', 'in:up,down'],
            'orden_nuevo' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            if (! empty($datos['direccion'])) {
                $delta = $datos['direccion'] === 'up' ? -1 : 1;
                $this->detalleService->moverLineaRelativo(
                    $nota,
                    $datos['prod_item'],
                    (int) $datos['orden'],
                    $delta,
                );
            } else {
                if (! isset($datos['orden_nuevo'])) {
                    return response()->json(['error' => 'Indique dirección o nuevo orden.'], 422);
                }

                $this->detalleService->cambiarOrden(
                    $nota,
                    $datos['prod_item'],
                    (int) $datos['orden'],
                    (int) $datos['orden_nuevo'],
                );
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Grabado con éxito.',
            'lineas' => $this->detalleService->lineasOrdenJson($nota),
        ]);
    }

    public function importarCompraAgilPreview(Request $request, int $nronota): JsonResponse
    {
        $nota = Nota::query()->findOrFail($nronota);

        if (! $this->puedeVer($request, $nota)) {
            abort(403);
        }

        $datos = $request->validate([
            'texto' => ['required', 'string', 'max:50000'],
            'desde' => ['nullable', 'integer', 'min:0'],
            'hasta' => ['nullable', 'integer', 'min:0'],
        ]);

        if (isset($datos['desde'], $datos['hasta'])) {
            $resultado = $this->compraAgilImport->previewLote(
                $datos['texto'],
                (int) $datos['desde'],
                (int) $datos['hasta'],
            );

            $errorCabecera = null;
            $puedeImportar = true;

            if ((int) $datos['desde'] === 0 && ($resultado['cabecera']['codigo_cotizacion'] ?? '') !== '') {
                $errorCabecera = $this->notaService->validarNumeroCotizacionDisponible(
                    $nota,
                    $resultado['cabecera']['codigo_cotizacion'],
                    true,
                );
                if ($errorCabecera !== null) {
                    $puedeImportar = false;
                }
            }

            return response()->json(array_merge($resultado, [
                'error_cabecera' => $errorCabecera,
                'puede_importar' => $puedeImportar,
            ]));
        }

        $preview = $this->compraAgilImport->preview($datos['texto']);
        $errorCabecera = null;
        $puedeImportar = true;

        if ($preview['cabecera']['codigo_cotizacion'] !== '') {
            $errorCabecera = $this->notaService->validarNumeroCotizacionDisponible(
                $nota,
                $preview['cabecera']['codigo_cotizacion'],
                true,
            );
            if ($errorCabecera !== null) {
                $puedeImportar = false;
            }
        }

        return response()->json(array_merge($preview, [
            'error_cabecera' => $errorCabecera,
            'puede_importar' => $puedeImportar,
        ]));
    }

    public function coincidenciasCompraAgil(Request $request, int $nronota): JsonResponse
    {
        $nota = Nota::query()->findOrFail($nronota);

        if (! $this->puedeVer($request, $nota)) {
            abort(403);
        }

        $request->validate([
            'texto' => ['nullable', 'string', 'max:50000'],
        ]);

        $detalle = $this->detalleService->resumenLineasNota($nota);

        return response()->json([
            'con_agile' => $detalle['con_agile'],
            'total' => $detalle['con_agile'],
            'detalle' => $detalle,
        ]);
    }

    public function limpiarLineasAgileCompraAgil(Request $request, int $nronota): JsonResponse
    {
        $nota = Nota::query()->findOrFail($nronota);

        if (! $this->puedeVer($request, $nota)) {
            abort(403);
        }

        $eliminadas = $this->detalleService->eliminarTodasLineasAgile($nota);

        return response()->json([
            'ok' => true,
            'eliminadas' => $eliminadas,
            'detalle' => $this->detalleService->resumenLineasNota($nota->fresh()),
        ]);
    }

    public function importarCompraAgil(Request $request, int $nronota): JsonResponse
    {
        $nota = Nota::query()->findOrFail($nronota);

        if (! $this->puedeVer($request, $nota)) {
            abort(403);
        }

        $datos = $request->validate([
            'texto' => ['required', 'string', 'max:50000'],
            'desde' => ['nullable', 'integer', 'min:0'],
            'hasta' => ['nullable', 'integer', 'min:0'],
        ]);

        $parseado = $this->compraAgilImport->parseTexto($datos['texto']);

        if ($nota->requiereNumeroCotizacion() && $parseado['cabecera']['codigo_cotizacion'] === '') {
            return response()->json([
                'error' => 'Debe ingresar el número de cotización antes de continuar, o pegar un texto que lo incluya.',
            ], 422);
        }

        if (($parseado['cabecera']['codigo_cotizacion'] ?? '') !== ''
            && (! isset($datos['desde']) || (int) $datos['desde'] === 0)) {
            if ($error = $this->notaService->validarNumeroCotizacionDisponible(
                $nota,
                $parseado['cabecera']['codigo_cotizacion'],
                true,
            )) {
                return response()->json(['error' => $error], 422);
            }
        }

        try {
            if (isset($datos['desde'], $datos['hasta'])) {
                $resultado = $this->compraAgilImport->aplicarLote(
                    $nota,
                    $datos['texto'],
                    $request->user()->username,
                    (int) $datos['desde'],
                    (int) $datos['hasta'],
                );
            } else {
                $resultado = $this->compraAgilImport->aplicar($nota, $datos['texto'], $request->user()->username);
            }
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => config('app.debug')
                    ? $e->getMessage()
                    : 'Error interno al importar. Intente con menos líneas o contacte al administrador.',
            ], 500);
        }

        return response()->json(array_merge([
            'ok' => true,
        ], $resultado));
    }

    public function vincularLineaAgile(Request $request, int $nronota): JsonResponse
    {
        $nota = Nota::query()->findOrFail($nronota);

        if (! $this->puedeVer($request, $nota)) {
            abort(403);
        }

        if ($error = $this->notaService->validarNumeroCotizacion($nota)) {
            return response()->json(['error' => $error], 422);
        }

        $datos = $request->validate([
            'orden' => ['required', 'integer', 'min:1'],
            'prod_item_agile' => ['required', 'string', 'max:50'],
            'prod_item' => ['required', 'string', 'max:50'],
            'prod_valor' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $resultado = $this->detalleService->vincularLineaAgile(
                $nota,
                (int) $datos['orden'],
                $datos['prod_item_agile'],
                $datos['prod_item'],
                $request->user()->username,
                isset($datos['prod_valor']) ? (int) $datos['prod_valor'] : null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'linea' => $resultado,
        ]);
    }

    public function buscarProductos(Request $request): JsonResponse
    {
        $term = $request->string('q')->trim()->toString();
        $familia = $request->string('familia')->trim()->toString() ?: null;
        $limiteConfig = (int) config('cotiz.buscar_productos_limite', 15);
        $maxLimite = (int) config('cotiz.buscar_productos_max_limite', 50);
        $limit = min(
            max(1, (int) $request->input('limit', $limiteConfig)),
            max(1, $maxLimite),
        );

        $productos = $this->detalleService->buscarProductos($term, $familia, $limit);

        return response()->json([
            'data' => $productos->map(fn (Maeprod $p) => [
                'prod_item' => (string) $p->prod_item,
                'prod_nombre' => $p->prod_nombre,
                'prod_valor' => $p->prod_valor,
                'prod_valor_costo' => $p->prod_valor_costo,
                'prod_familia' => $p->prod_familia,
                'prod_gramaje' => $p->prod_gramaje,
                'image_url' => $p->imageUrl(),
            ]),
            'meta' => [
                'q' => $term,
                'count' => $productos->count(),
                'limit' => $limit,
                'min_chars' => (int) config('cotiz.buscar_productos_min_chars', 2),
            ],
        ]);
    }

    private function notaNoEncontrada(int $nronota): RedirectResponse
    {
        return redirect()
            ->route('admin.cotizaciones.index')
            ->with(
                'error',
                "La cotización #{$nronota} no existe. Use el listado para abrirla o cree una nueva.",
            );
    }

    private function redirectTrasGuardar(Request $request, int $nronota, string $mensaje): RedirectResponse
    {
        $params = ['nronota' => $nronota];
        if ($request->query('from') === 'adjudicadas') {
            $params['from'] = 'adjudicadas';
        }

        return redirect()
            ->route('admin.cotizaciones.edit', $params)
            ->with('success', $mensaje);
    }

    /**
     * @return array<string, mixed>
     */
    private function reglasCabecera(): array
    {
        return [
            'descripcion' => ['required', 'string', 'max:500'],
            'empresa' => ['nullable', 'string', 'max:100'],
            'encargado' => ['required', 'string', 'max:100'],
            'celular' => ['nullable', 'string', 'max:15'],
            'contacto' => ['nullable', 'string', 'max:100'],
            'contactocorreo' => ['nullable', 'string', 'max:60'],
            'rutempresa' => ['nullable', 'string', 'max:10'],
            'diashabiles' => ['nullable', 'integer', 'min:0'],
            'ocompra' => ['nullable', 'string', 'max:20'],
            'fechaentrega' => ['nullable', 'date'],
            'factor_precio_venta' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reglasLineasLote(): array
    {
        return [
            'lineas' => ['required', 'array', 'min:1', 'max:10'],
            'lineas.*.prod_item' => ['required', 'string', 'max:50'],
            'lineas.*.orden' => ['required', 'integer'],
            'lineas.*.cantidad' => ['nullable', 'integer', 'min:1'],
            'lineas.*.prod_valor' => ['nullable', 'integer', 'min:0'],
            'lineas.*.prod_valor_costo' => ['nullable', 'integer', 'min:0'],
            'lineas.*.prod_item_softland' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * Líneas empaquetadas en JSON (1 campo POST) para evitar truncado por max_input_vars.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function lineasDesdeJson(Request $request): ?array
    {
        $json = $request->string('lineas_json')->trim()->toString();
        if ($json === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return null;
        }

        $lineas = [];
        foreach ($decoded as $item) {
            if (! is_array($item) || empty($item['prod_item']) || ! isset($item['orden'])) {
                continue;
            }
            $lineas[] = [
                'prod_item' => (string) $item['prod_item'],
                'orden' => (int) $item['orden'],
                'cantidad' => isset($item['cantidad']) ? (int) $item['cantidad'] : null,
                'prod_valor' => isset($item['prod_valor']) ? (int) $item['prod_valor'] : null,
                'prod_valor_costo' => isset($item['prod_valor_costo']) ? (int) $item['prod_valor_costo'] : null,
                'prod_item_softland' => isset($item['prod_item_softland']) ? (string) $item['prod_item_softland'] : null,
            ];
        }

        return $lineas === [] ? null : $lineas;
    }

    private function puedeVer(Request $request, Nota $nota): bool
    {
        $user = $request->user();

        if ($user->perfil === User::PERFIL_SUPERADMIN) {
            return true;
        }

        return $nota->usuario === $user->username;
    }

    private function rechazarSinNumeroCotizacion(Request $request, Nota $nota): RedirectResponse|JsonResponse|null
    {
        if ($error = $this->notaService->validarNumeroCotizacion($nota)) {
            if ($request->expectsJson()) {
                return response()->json(['error' => $error], 422);
            }

            return back()->with('error', $error);
        }

        return null;
    }
}

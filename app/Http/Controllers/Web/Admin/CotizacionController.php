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
                ->with('error', 'Debe ingresar el número de cotización en la nota #'.$pendiente->nronota.' antes de crear una nueva.');
        }

        $nota = $this->notaService->crear($usuario);

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
        $nota = Nota::query()->with('detalle.producto')->findOrFail($nronota);

        if (! $this->puedeVer($request, $nota)) {
            abort(403);
        }

        $lineas = $this->detalleService->lineasDeNota($nota);
        $hayPrecioAntiguo = $lineas->contains(fn ($row) => $row['prod_valor_fecha_antigua']);

        return view('admin.cotizaciones.form', [
            'nota' => $nota,
            'lineas' => $lineas,
            'total' => $lineas->sum(fn ($row) => $row['total']),
            'hayPrecioAntiguo' => $hayPrecioAntiguo,
            'umbralPrecioMeses' => config('cotiz.prod_valor_fecha_meses'),
            'requiereNumeroCotizacion' => $nota->requiereNumeroCotizacion(),
        ]);
    }

    public function update(Request $request, int $nronota): RedirectResponse
    {
        $nota = Nota::query()->findOrFail($nronota);

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

            return back()->with(
                'success',
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

        $datos = $request->validate([
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
            'lineas' => ['nullable', 'array'],
            'lineas.*.prod_item' => ['required_with:lineas', 'string', 'max:50'],
            'lineas.*.orden' => ['required_with:lineas', 'integer'],
            'lineas.*.cantidad' => ['nullable', 'integer', 'min:1'],
            'lineas.*.prod_valor' => ['nullable', 'integer', 'min:0'],
            'lineas.*.prod_valor_costo' => ['nullable', 'integer', 'min:0'],
            'lineas.*.prod_item_softland' => ['nullable', 'string', 'max:20'],
        ]);

        if ($error = $this->notaService->validarNumeroCotizacion($nota, $datos['encargado'])) {
            return back()->withInput()->withErrors(['encargado' => $error]);
        }

        $lineas = $datos['lineas'] ?? [];
        unset($datos['lineas']);

        $eraSinNumero = $nota->requiereNumeroCotizacion();

        $this->notaService->modificarCabecera($nota, $datos);

        if ($lineas !== []) {
            $this->detalleService->guardarLineas($nota->fresh(), $lineas, $request->user()->username);
        }

        $mensaje = $eraSinNumero
            ? 'Número de cotización guardado. Ya puede agregar productos.'
            : 'Cotización guardada.';

        return back()->with('success', $mensaje);
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

        $this->detalleService->agregarLinea(
            $nota,
            $datos['prod_item'],
            (int) $datos['cantidad'],
            (int) $datos['prod_valor'],
            isset($datos['prod_valor_costo']) ? (int) $datos['prod_valor_costo'] : null,
            $request->user()->username,
        );

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Línea agregada.');
    }

    public function eliminarLinea(Request $request, int $nronota): RedirectResponse
    {
        $nota = Nota::query()->findOrFail($nronota);

        if (! $this->puedeVer($request, $nota)) {
            abort(403);
        }

        if ($respuesta = $this->rechazarSinNumeroCotizacion($request, $nota)) {
            return $respuesta;
        }

        $datos = $request->validate([
            'prod_item' => ['required', 'string'],
            'orden' => ['required', 'integer'],
        ]);

        $this->detalleService->eliminarLinea($nota, $datos['prod_item'], (int) $datos['orden']);

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
            'lineas' => NotaDetalle::query()
                ->where('nronota', $nota->nronota)
                ->orderBy('orden')
                ->get(['prod_item', 'orden', 'prod_item_agile'])
                ->map(fn (NotaDetalle $linea) => [
                    'prod_item' => $linea->prod_item,
                    'orden' => (int) $linea->orden,
                    'prod_item_agile' => $linea->prod_item_agile,
                ])
                ->values()
                ->all(),
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
                $errorCabecera = $this->notaService->validarNumeroCotizacion(
                    $nota,
                    $resultado['cabecera']['codigo_cotizacion'],
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
            $errorCabecera = $this->notaService->validarNumeroCotizacion(
                $nota,
                $preview['cabecera']['codigo_cotizacion'],
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

        $datos = $request->validate([
            'texto' => ['required', 'string', 'max:50000'],
        ]);

        $ids = $this->compraAgilImport->idsAgileDelTexto($datos['texto']);
        $coincidencias = $this->detalleService->idsAgileExistentesEnNota($nota, $ids);

        return response()->json([
            'coincidencias' => $coincidencias,
            'total' => count($coincidencias),
        ]);
    }

    public function limpiarLineasAgileCompraAgil(Request $request, int $nronota): JsonResponse
    {
        $nota = Nota::query()->findOrFail($nronota);

        if (! $this->puedeVer($request, $nota)) {
            abort(403);
        }

        $datos = $request->validate([
            'texto' => ['required', 'string', 'max:50000'],
        ]);

        $ids = $this->compraAgilImport->idsAgileDelTexto($datos['texto']);
        $coincidencias = $this->detalleService->idsAgileExistentesEnNota($nota, $ids);
        $eliminadas = $this->detalleService->eliminarLineasPorAgileIds($nota, $coincidencias);

        return response()->json([
            'ok' => true,
            'coincidencias' => $coincidencias,
            'eliminadas' => $eliminadas,
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
                'prod_item' => $p->prod_item,
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

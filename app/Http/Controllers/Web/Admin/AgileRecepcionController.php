<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Nota;
use App\Services\AgileRecepcionService;
use App\Services\NotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class AgileRecepcionController extends Controller
{
    public function __construct(
        protected AgileRecepcionService $agileService,
        protected NotaService $notaService,
    ) {}

    public function index(Request $request): View
    {
        $campo = $request->input('campo', 'encargado');
        if (! in_array($campo, ['encargado', 'rutempresa', 'empresa'], true)) {
            $campo = 'encargado';
        }

        $filtros = [
            'campo' => $campo,
            'valor' => trim((string) $request->input('valor', '')),
        ];

        $cotizaciones = $this->agileService->listar($request->user(), $filtros);

        return view('admin.agile.index', [
            'cotizaciones' => $cotizaciones,
            'filtros' => $filtros,
        ]);
    }

    public function show(Request $request, int $nronota): View|RedirectResponse
    {
        $nota = Nota::query()->findOrFail($nronota);

        if (! $this->agileService->puedeVer($request->user(), $nota)) {
            return redirect()
                ->route('admin.agile.index')
                ->with('error', 'Cotización no encontrada o sin permisos.');
        }

        $detalle = $this->agileService->detalle($nota);
        $lineas = $detalle['lineas'];

        $hayPrecioAntiguo = $lineas->contains(fn (array $row) => $row['prod_valor_fecha_antigua']);
        $umbralMeses = (int) config('cotiz.prod_valor_fecha_meses', 1);

        $factorValor = (float) ($nota->factor_precio_venta ?? config('cotiz.factor_precio_venta'));
        $factorMostrado = number_format($factorValor, 2, ',', '');

        return view('admin.agile.show', [
            'nota' => $nota,
            'lineas' => $lineas,
            'hayPrecioAntiguo' => $hayPrecioAntiguo,
            'umbralPrecioMeses' => $umbralMeses,
            'factorMostrado' => $factorMostrado,
            'factorInput' => old('factor_precio_venta', $factorMostrado),
            'estaAprobada' => strcasecmp((string) $nota->estado, 'Aprobada') === 0,
        ]);
    }

    public function aprobar(Request $request, int $nronota): JsonResponse|RedirectResponse
    {
        $nota = Nota::query()->findOrFail($nronota);

        if (! $this->agileService->puedeVer($request->user(), $nota)) {
            abort(403);
        }

        try {
            $this->agileService->aprobar($nota, $request->user()->username);
        } catch (RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json(['error' => $e->getMessage()], 422);
            }

            return redirect()
                ->route('admin.agile.show', $nronota)
                ->with('error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Cotización aprobada correctamente.',
                'nronota' => $nronota,
            ]);
        }

        return redirect()
            ->route('admin.cotizaciones.edit', $nronota)
            ->with('success', 'Cotización aprobada. Nota: '.$nronota);
    }

    public function destroy(Request $request, int $nronota): RedirectResponse
    {
        $nota = Nota::query()->findOrFail($nronota);

        if (! $this->agileService->puedeVer($request->user(), $nota)) {
            abort(403);
        }

        if (strcasecmp((string) $nota->estado, 'Aprobada') === 0) {
            return redirect()
                ->route('admin.agile.show', $nronota)
                ->with('error', 'No se puede eliminar una cotización aprobada.');
        }

        $this->agileService->eliminar($nota);

        return redirect()
            ->route('admin.agile.index')
            ->with('success', 'Cotización eliminada.');
    }

    public function actualizarFactor(Request $request, int $nronota): JsonResponse
    {
        $nota = Nota::query()->findOrFail($nronota);

        if (! $this->agileService->puedeVer($request->user(), $nota)) {
            abort(403);
        }

        $factor = $this->notaService->parseFactorPrecioVenta($request->input('factor_precio_venta'));
        if ($factor === null) {
            return response()->json(['error' => 'Factor inválido'], 422);
        }

        try {
            $resultado = $this->agileService->aplicarFactorPrecioVenta(
                $nota,
                $factor,
                $request->user()->username
            );
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'factor_precio_venta_fmt' => number_format($resultado['factor'], 2, ',', ''),
            'lineas' => $resultado['lineas'],
        ]);
    }

    public function actualizarPrecio(Request $request, int $nronota): JsonResponse
    {
        $nota = Nota::query()->findOrFail($nronota);

        if (! $this->agileService->puedeVer($request->user(), $nota)) {
            abort(403);
        }

        $validated = $request->validate([
            'orden' => ['required', 'integer', 'min:1'],
            'prod_item_agile' => ['required', 'string', 'max:50'],
            'prod_valor' => ['nullable', 'integer', 'min:0'],
            'prod_item' => ['nullable', 'string', 'max:50'],
            'prod_descripcion_agile' => ['nullable', 'string', 'max:500'],
            'factor_precio_venta' => ['nullable', 'string'],
        ]);

        try {
            $linea = $this->agileService->actualizarPrecioLinea(
                $nota,
                $validated,
                $request->user()->username
            );
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        [$fechaFmt, $fechaAntigua] = \App\Support\ProdValorFechaUi::textoYAntigua(
            $linea->producto?->prod_valor_fecha
        );

        return response()->json([
            'success' => true,
            'prod_valor' => (int) $linea->prod_valor,
            'prod_valor_costo' => (int) $linea->prod_valor_costo,
            'prod_item' => $linea->prod_item,
            'prod_valor_fecha_fmt' => $fechaFmt,
            'prod_valor_fecha_antigua' => $fechaAntigua ? 1 : 0,
            'subtotal' => $linea->lineTotal(),
        ]);
    }

    public function buscarProductos(Request $request): JsonResponse
    {
        $texto = trim((string) $request->input('q', $request->input('texto', '')));

        return response()->json([
            'productos' => $this->agileService->buscarProductosParaPopup($texto),
        ]);
    }
}

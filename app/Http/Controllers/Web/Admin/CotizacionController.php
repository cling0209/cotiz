<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Maeprod;
use App\Models\Nota;
use App\Services\NotaDetalleService;
use App\Services\NotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CotizacionController extends Controller
{
    public function __construct(
        protected NotaService $notaService,
        protected NotaDetalleService $detalleService,
    ) {}

    public function create(Request $request): RedirectResponse
    {
        $nota = $this->notaService->crear($request->user()->username);

        return redirect()->route('admin.cotizaciones.edit', $nota->nronota)
            ->with('success', 'Cotización '.$nota->nronota.' creada.');
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

        return view('admin.cotizaciones.form', [
            'nota' => $nota,
            'lineas' => $lineas,
            'total' => $lineas->sum(fn ($row) => $row['total']),
        ]);
    }

    public function update(Request $request, int $nronota): RedirectResponse
    {
        $nota = Nota::query()->findOrFail($nronota);

        if (! $this->puedeVer($request, $nota)) {
            abort(403);
        }

        $datos = $request->validate([
            'descripcion' => ['required', 'string', 'max:500'],
            'empresa' => ['nullable', 'string', 'max:100'],
            'encargado' => ['nullable', 'string', 'max:100'],
            'celular' => ['nullable', 'string', 'max:15'],
            'contacto' => ['nullable', 'string', 'max:100'],
            'contactocorreo' => ['nullable', 'string', 'max:60'],
            'rutempresa' => ['nullable', 'string', 'max:10'],
            'diashabiles' => ['nullable', 'integer', 'min:0'],
            'ocompra' => ['nullable', 'string', 'max:20'],
            'fechaentrega' => ['nullable', 'date'],
            'factor_precio_venta' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->notaService->modificarCabecera($nota, $datos);

        return back()->with('success', 'Cotización guardada.');
    }

    public function agregarLinea(Request $request, int $nronota): RedirectResponse|JsonResponse
    {
        $nota = Nota::query()->findOrFail($nronota);

        if (! $this->puedeVer($request, $nota)) {
            abort(403);
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

        $datos = $request->validate([
            'prod_item' => ['required', 'string'],
            'orden' => ['required', 'integer'],
        ]);

        $this->detalleService->eliminarLinea($nota, $datos['prod_item'], (int) $datos['orden']);

        return back()->with('success', 'Línea eliminada.');
    }

    public function buscarProductos(Request $request): JsonResponse
    {
        $term = $request->string('q')->trim()->toString();
        $familia = $request->string('familia')->trim()->toString() ?: null;

        $productos = $this->detalleService->buscarProductos($term, $familia);

        return response()->json([
            'data' => $productos->map(fn (Maeprod $p) => [
                'prod_item' => $p->prod_item,
                'prod_nombre' => $p->prod_nombre,
                'prod_valor' => $p->prod_valor,
                'prod_valor_costo' => $p->prod_valor_costo,
                'prod_familia' => $p->prod_familia,
                'image_url' => $p->imageUrl(),
            ]),
        ]);
    }

    private function puedeVer(Request $request, Nota $nota): bool
    {
        $user = $request->user();

        if ($user->perfil === \App\Models\User::PERFIL_SUPERADMIN) {
            return true;
        }

        return $nota->usuario === $user->username;
    }
}

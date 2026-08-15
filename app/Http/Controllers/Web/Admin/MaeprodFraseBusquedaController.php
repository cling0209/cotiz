<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Maeprod;
use App\Models\MaeprodFraseBusqueda;
use App\Services\MaeprodFraseBusquedaService;
use App\Support\ListadoPorPagina;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MaeprodFraseBusquedaController extends Controller
{
    public function __construct(
        protected MaeprodFraseBusquedaService $frases,
    ) {}

    public function index(Request $request): View
    {
        abort_unless(
            $request->user()->canManageMaeprodFrases(),
            403,
            'Acceso no autorizado.',
        );

        $porPagina = ListadoPorPagina::resolver($request, 'producto-mp-frases');
        $q = $request->string('q')->trim()->toString();

        $frases = MaeprodFraseBusqueda::query()
            ->with('producto')
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.$q.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('frase', 'like', $like)
                        ->orWhere('prod_item', 'like', $like)
                        ->orWhereHas('producto', fn ($p) => $p->where('prod_nombre', 'like', $like));
                });
            })
            ->orderBy('prod_item')
            ->orderBy('frase')
            ->paginate($porPagina)
            ->withQueryString();

        return view('admin.producto-mp.frases.index', [
            'frases' => $frases,
            'filtros' => ['q' => $q],
            'puedeBuscarMp' => (bool) $request->user()?->canAccessOportunidades(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(
            $request->user()->canManageMaeprodFrases(),
            403,
            'Acceso no autorizado.',
        );

        $request->validate([
            'prod_item' => ['required', 'string', 'max:50'],
            'frase' => ['required', 'string', 'min:2', 'max:200'],
        ], [
            'prod_item.required' => 'Indique el código de producto.',
            'frase.required' => 'Indique la frase de búsqueda.',
        ]);

        $producto = Maeprod::query()->find($request->string('prod_item')->trim()->toString());
        if (! $producto) {
            return redirect()
                ->route('admin.producto-mp.frases.index')
                ->withErrors(['prod_item' => 'El producto no existe.']);
        }

        $this->frases->agregar($producto, (string) $request->input('frase'));

        $destino = $request->input('redirect_producto')
            ? route('admin.productos.edit', $producto->prod_item)
            : route('admin.producto-mp.frases.index');

        return redirect($destino)->with('success', 'Frase de búsqueda MP agregada.');
    }

    public function destroy(Request $request, int $frase): RedirectResponse
    {
        abort_unless(
            $request->user()->canManageMaeprodFrases(),
            403,
            'Acceso no autorizado.',
        );

        $fraseModel = MaeprodFraseBusqueda::query()->find($frase);
        if ($fraseModel === null) {
            return redirect()
                ->route('admin.producto-mp.frases.index')
                ->with('info', 'La frase ya estaba eliminada.');
        }

        $prodItem = $fraseModel->prod_item;
        $producto = Maeprod::query()->find($prodItem);
        if ($producto) {
            $this->frases->eliminar($producto, $fraseModel);
        } else {
            $fraseModel->delete();
        }

        $destino = $request->input('redirect_producto')
            ? route('admin.productos.edit', $prodItem)
            : route('admin.producto-mp.frases.index');

        return redirect($destino)->with('success', 'Frase de búsqueda MP eliminada.');
    }
}

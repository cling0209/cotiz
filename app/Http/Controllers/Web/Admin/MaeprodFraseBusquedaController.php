<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Maeprod;
use App\Models\MaeprodFraseBusqueda;
use App\Services\CompraAgilRegionScope;
use App\Services\MaeprodFraseBusquedaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MaeprodFraseBusquedaController extends Controller
{
    private const ORDEN_CAMPOS = ['frase', 'creador', 'fecha'];

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

        $ordenCampo = (string) $request->input('orden_campo', 'frase');
        if (! in_array($ordenCampo, self::ORDEN_CAMPOS, true)) {
            $ordenCampo = 'frase';
        }

        $ordenDir = strtoupper((string) $request->input('orden_dir', 'ASC'));
        if (! in_array($ordenDir, ['ASC', 'DESC'], true)) {
            $ordenDir = 'ASC';
        }

        $query = MaeprodFraseBusqueda::query()->with(['creador', 'regiones']);

        if ($ordenCampo === 'creador') {
            $query
                ->leftJoin('users', 'users.id', '=', 'maeprod_frases_busqueda.created_by')
                ->select('maeprod_frases_busqueda.*')
                ->orderByRaw('LOWER(COALESCE(users.username, \'\')) '.$ordenDir)
                ->orderBy('maeprod_frases_busqueda.id');
        } elseif ($ordenCampo === 'fecha') {
            $query
                ->orderBy('created_at', $ordenDir)
                ->orderBy('id');
        } else {
            $query
                ->orderByRaw('LOWER(frase) '.$ordenDir)
                ->orderBy('id');
        }

        $palabras = $query->get();

        return view('admin.producto-mp.frases.index', [
            'palabras' => $palabras,
            'filtros' => [
                'orden_campo' => $ordenCampo,
                'orden_dir' => $ordenDir,
            ],
            'regionesDisponibles' => $this->regionesDisponibles(),
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
            'frase' => ['required', 'string', 'min:2', 'max:200'],
            'prod_item' => ['nullable', 'string', 'max:50'],
            'regiones' => ['nullable', 'array'],
            'regiones.*' => ['integer'],
        ], [
            'frase.required' => 'Indique la palabra clave.',
            'frase.min' => 'La palabra clave debe tener al menos 2 caracteres.',
        ]);

        $producto = null;
        $prodItem = $request->string('prod_item')->trim()->toString();
        if ($prodItem !== '') {
            $producto = Maeprod::query()->find($prodItem);
            if (! $producto) {
                return redirect()
                    ->route('admin.producto-mp.frases.index')
                    ->withErrors(['frase' => 'El producto no existe.']);
            }
        }

        $this->frases->agregar(
            (string) $request->input('frase'),
            $producto,
            $this->normalizarRegiones($request),
            $request->user()?->id,
        );

        $destino = $request->boolean('redirect_producto') && $producto !== null
            ? route('admin.productos.edit', $producto->prod_item)
            : route('admin.producto-mp.frases.index');

        return redirect($destino)->with('success', 'Palabra clave agregada.');
    }

    public function update(Request $request, MaeprodFraseBusqueda $frase): RedirectResponse
    {
        abort_unless(
            $request->user()->canManageMaeprodFrases(),
            403,
            'Acceso no autorizado.',
        );

        $request->validate([
            'regiones' => ['nullable', 'array'],
            'regiones.*' => ['integer'],
        ]);

        $this->frases->syncRegiones($frase, $this->normalizarRegiones($request));

        return redirect()
            ->route('admin.producto-mp.frases.index')
            ->with('success', 'Regiones de «'.$frase->frase.'» actualizadas.');
    }

    public function destroy(Request $request, MaeprodFraseBusqueda $frase): RedirectResponse
    {
        abort_unless(
            $request->user()->canManageMaeprodFrases(),
            403,
            'Acceso no autorizado.',
        );

        $prodItem = $frase->prod_item;
        $this->frases->eliminar($frase);

        $destino = $request->boolean('redirect_producto') && $prodItem
            ? route('admin.productos.edit', $prodItem)
            : route('admin.producto-mp.frases.index');

        return redirect($destino)->with('success', 'Palabra clave eliminada.');
    }

    /**
     * @return array<int, string>
     */
    private function regionesDisponibles(): array
    {
        $out = [];
        foreach (CompraAgilRegionScope::regionesIncluidas() as $codigo) {
            $out[(int) $codigo] = CompraAgilRegionScope::nombreRegion((int) $codigo);
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private function normalizarRegiones(Request $request): array
    {
        $permitidas = CompraAgilRegionScope::regionesIncluidas();
        $input = $request->input('regiones', []);
        if (! is_array($input)) {
            return [];
        }

        $codigos = [];
        foreach ($input as $valor) {
            $codigo = (int) $valor;
            if ($codigo > 0 && in_array($codigo, $permitidas, true)) {
                $codigos[$codigo] = $codigo;
            }
        }

        return array_values($codigos);
    }
}

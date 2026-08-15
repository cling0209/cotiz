<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductoMpEncontrado;
use App\Services\CompraAgilRegionScope;
use App\Services\ProductoMpBusquedaService;
use App\Support\ListadoPorPagina;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class ProductoMpEncontradoController extends Controller
{
    public function __construct(
        protected ProductoMpBusquedaService $busqueda,
    ) {}

    public function index(Request $request): View
    {
        abort_unless(
            $request->user()->canVerOportunidades(),
            403,
            'Acceso no autorizado.',
        );

        $porPagina = ListadoPorPagina::resolver($request, 'producto-mp-encontrados');
        $q = $request->string('q')->trim()->toString();
        $prodItem = $request->string('prod_item')->trim()->toString();
        $region = $request->integer('region');

        $matches = ProductoMpEncontrado::query()
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.$q.'%';
                $query->where(function ($inner) use ($like) {
                    $inner->where('codigo', 'like', $like)
                        ->orWhere('descripcion_mp', 'like', $like)
                        ->orWhere('frase', 'like', $like)
                        ->orWhere('prod_nombre', 'like', $like)
                        ->orWhere('organismo', 'like', $like)
                        ->orWhere('nombre_ca', 'like', $like);
                });
            })
            ->when($prodItem !== '', fn ($query) => $query->where('prod_item', $prodItem))
            ->when($region > 0, fn ($query) => $query->where('region', $region))
            ->orderByDesc('fecha_cierre')
            ->orderBy('prod_item')
            ->orderBy('codigo')
            ->paginate($porPagina)
            ->withQueryString();

        $regionesFiltro = [];
        foreach (CompraAgilRegionScope::regionesIncluidas() as $codigoRegion) {
            $regionesFiltro[(int) $codigoRegion] = CompraAgilRegionScope::nombreRegion((int) $codigoRegion);
        }

        return view('admin.producto-mp.encontrados.index', [
            'matches' => $matches,
            'filtros' => [
                'q' => $q,
                'prod_item' => $prodItem,
                'region' => $region > 0 ? $region : '',
            ],
            'regionesFiltro' => $regionesFiltro,
            'puedeBuscar' => (bool) $request->user()?->canAccessOportunidades(),
            'puedeFrases' => (bool) $request->user()?->canManageMaeprodFrases(),
            'corridaEstado' => $this->busqueda->estado(),
            'mpBaseUrl' => rtrim((string) config('cotiz.mercadopublico.base_url'), '/'),
        ]);
    }

    public function iniciar(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->canAccessOportunidades(),
            403,
            'Acceso no autorizado.',
        );

        try {
            $corrida = $this->busqueda->iniciar((string) ($request->user()?->username ?? 'admin'));
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'corrida' => $this->busqueda->estado($corrida),
        ]);
    }

    public function estado(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->canAccessOportunidades(),
            403,
            'Acceso no autorizado.',
        );

        return response()->json([
            'ok' => true,
            'corrida' => $this->busqueda->estado(),
        ]);
    }

    public function cancelar(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->canAccessOportunidades(),
            403,
            'Acceso no autorizado.',
        );

        $corrida = $this->busqueda->cancelar((string) ($request->user()?->username ?? 'admin'));

        return response()->json([
            'ok' => true,
            'corrida' => $this->busqueda->estado($corrida),
        ]);
    }
}

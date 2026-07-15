<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\OportunidadPalabraClave;
use App\Services\OportunidadParaCotizarService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OportunidadParaCotizarController extends Controller
{
    public function __construct(
        protected OportunidadParaCotizarService $servicio,
    ) {}

    public function index(Request $request): View
    {
        $palabras = OportunidadPalabraClave::query()
            ->orderBy('frase')
            ->pluck('frase')
            ->map(fn ($f) => trim((string) $f))
            ->filter(fn ($f) => $f !== '')
            ->values()
            ->all();

        $buscar = $request->boolean('buscar');

        if (! $buscar) {
            return view('admin.oportunidades.para-cotizar.index', [
                'items' => [],
                'palabras' => $palabras,
                'errorApi' => null,
                'apiConfigurada' => true,
                'busquedaRealizada' => false,
            ]);
        }

        $resultado = $this->servicio->listar();

        return view('admin.oportunidades.para-cotizar.index', [
            'items' => $resultado['items'],
            'palabras' => $resultado['palabras'],
            'errorApi' => $resultado['error'],
            'apiConfigurada' => $resultado['api_configurada'],
            'busquedaRealizada' => true,
        ]);
    }
}

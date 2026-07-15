<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\OportunidadParaCotizarService;
use Illuminate\View\View;

class OportunidadParaCotizarController extends Controller
{
    public function __construct(
        protected OportunidadParaCotizarService $servicio,
    ) {}

    public function index(): View
    {
        $resultado = $this->servicio->listar();

        return view('admin.oportunidades.para-cotizar.index', [
            'items' => $resultado['items'],
            'palabras' => $resultado['palabras'],
            'errorApi' => $resultado['error'],
            'apiConfigurada' => $resultado['api_configurada'],
        ]);
    }
}

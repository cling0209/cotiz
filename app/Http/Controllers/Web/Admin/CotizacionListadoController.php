<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Nota;
use App\Services\NotaListadoService;
use App\Services\NotaService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CotizacionListadoController extends Controller
{
    public function __construct(
        protected NotaListadoService $listadoService,
        protected NotaService $notaService,
    ) {}

    public function index(Request $request): View
    {
        $filtros = $this->normalizarFiltros($request);
        $cotizaciones = $this->listadoService->listar($request->user(), $filtros);

        return view('admin.cotizaciones.index', [
            'cotizaciones' => $cotizaciones,
            'filtros' => $filtros,
        ]);
    }

    private function normalizarFiltros(Request $request): array
    {
        $nronota = (int) $request->input('nronota', 0);
        $cotizacion = trim((string) $request->input('cotizacion', ''));

        $fechadesde = $request->input('fechadesde');
        $fechahasta = $request->input('fechahasta');

        if ($nronota === 0 && $cotizacion === '') {
            $fechahasta = $fechahasta ?: now()->toDateString();
            $fechadesde = $fechadesde ?: now()->subMonth()->toDateString();
        }

        $ordenCampo = $request->input('orden_campo', 'nronota');
        if (! in_array($ordenCampo, ['nronota', 'fecha', 'total'], true)) {
            $ordenCampo = 'nronota';
        }

        $ordenDir = strtoupper((string) $request->input('orden_dir', 'DESC'));
        if (! in_array($ordenDir, ['ASC', 'DESC'], true)) {
            $ordenDir = 'DESC';
        }

        return [
            'nronota' => $nronota,
            'cotizacion' => $cotizacion,
            'fechadesde' => $fechadesde,
            'fechahasta' => $fechahasta,
            'orden_campo' => $ordenCampo,
            'orden_dir' => $ordenDir,
        ];
    }
}

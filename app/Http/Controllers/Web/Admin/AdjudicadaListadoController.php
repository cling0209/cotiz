<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\CotizacionListadoExportService;
use App\Services\NotaAdjudicadaListadoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdjudicadaListadoController extends Controller
{
    public function __construct(
        protected NotaAdjudicadaListadoService $adjudicadaService,
        protected CotizacionListadoExportService $exportService,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $filtros = $this->adjudicadaService->normalizarFiltros($request->all());

        if ($this->adjudicadaService->filtrosFechaInvalidos($filtros)) {
            return redirect()
                ->route('admin.cotizaciones.adjudicadas.index', array_filter([
                    'nronota' => $filtros['nronota'] ?: null,
                ]))
                ->with('error', 'Debe ingresar fecha entrega desde y hasta.');
        }

        $cotizaciones = $this->adjudicadaService->listar($request->user(), $filtros);

        return view('admin.cotizaciones.adjudicadas.index', [
            'cotizaciones' => $cotizaciones,
            'filtros' => $filtros,
        ]);
    }

    public function exportDetalle(Request $request): StreamedResponse|RedirectResponse
    {
        $filtros = $this->adjudicadaService->normalizarFiltros($request->all());

        if ($this->adjudicadaService->filtrosFechaInvalidos($filtros)) {
            return redirect()
                ->route('admin.cotizaciones.adjudicadas.index')
                ->with('error', 'Debe ingresar fecha entrega desde y hasta.');
        }

        return $this->exportService->respuestaAceptadasDetalleCsv($request->user(), $filtros);
    }

    public function exportSinCodigoSoftland(Request $request): StreamedResponse|RedirectResponse
    {
        if ($this->exportService->productosSinCodigoSoftland()->isEmpty()) {
            return redirect()
                ->route('admin.cotizaciones.adjudicadas.index')
                ->with(
                    'warning',
                    'No hay productos sin código Softland en cotizaciones aceptadas.',
                );
        }

        return $this->exportService->respuestaSinCodigoSoftlandTxt($request->user()->username);
    }
}

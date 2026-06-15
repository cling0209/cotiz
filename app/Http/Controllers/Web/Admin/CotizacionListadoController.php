<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Nota;
use App\Services\CotizacionListadoExportService;
use App\Services\NotaEnvioApiService;
use App\Services\NotaListadoService;
use App\Services\NotaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CotizacionListadoController extends Controller
{
    public function __construct(
        protected NotaListadoService $listadoService,
        protected NotaService $notaService,
        protected NotaEnvioApiService $envioApiService,
        protected CotizacionListadoExportService $exportService,
    ) {}

    public function index(Request $request): View
    {
        $filtros = $this->normalizarFiltros($request);
        $cotizaciones = $this->listadoService->listar($request->user(), $filtros);

        return view('admin.cotizaciones.index', [
            'cotizaciones' => $cotizaciones,
            'filtros' => $filtros,
            'puedeGestionar' => $this->listadoService->puedeGestionar($request->user()),
        ]);
    }

    public function enviar(Request $request, int $nronota): RedirectResponse
    {
        $nota = Nota::query()->findOrFail($nronota);
        $user = $request->user();

        if (! $this->listadoService->puedeVer($user, $nota)) {
            abort(403);
        }

        if ((int) $nota->enviadoapi !== 0) {
            return $this->volverListado($request)->with('error', 'La cotización ya fue enviada.');
        }

        $this->notaService->marcarEnviadoApi($nota, 1);

        try {
            $this->envioApiService->enviar($nota->fresh(), $user->username);
        } catch (RuntimeException $e) {
            $this->notaService->marcarEnviadoApi($nota, 0);

            return $this->volverListado($request)->with('error', $e->getMessage());
        }

        return $this->volverListado($request)->with('success', 'Cotización enviada correctamente.');
    }

    public function aceptar(Request $request, int $nronota): RedirectResponse
    {
        $nota = $this->notaGestionable($request, $nronota);

        if ($this->notaService->estaAceptada($nota)) {
            return $this->volverListado($request)->with('info', 'La cotización ya está aceptada.');
        }

        $this->notaService->aceptar($nota, $request->user()->username);

        return $this->volverListado($request)->with('success', 'Cotización aceptada.');
    }

    public function noAceptar(Request $request, int $nronota): RedirectResponse
    {
        $nota = $this->notaGestionable($request, $nronota);

        if (! $this->notaService->estaAceptada($nota)) {
            return $this->volverListado($request)->with('info', 'La cotización no está aceptada.');
        }

        $this->notaService->noAceptar($nota, $request->user()->username);

        return $this->volverListado($request)->with('success', 'Cotización marcada como no aceptada.');
    }

    public function asignarForm(Request $request, int $nronota): View|RedirectResponse
    {
        $nota = $this->notaGestionable($request, $nronota);

        if (trim((string) $nota->usuario) !== '') {
            return redirect()
                ->route('admin.cotizaciones.index')
                ->with('info', 'La cotización ya tiene usuario asignado.');
        }

        return view('admin.cotizaciones.asignar', [
            'nota' => $nota,
            'usuarios' => $this->listadoService->usuariosParaAsignar(),
        ]);
    }

    public function asignar(Request $request, int $nronota): RedirectResponse
    {
        $nota = $this->notaGestionable($request, $nronota);

        $validated = $request->validate([
            'usuario' => ['required', 'string', 'max:20', 'exists:users,username'],
        ]);

        $this->notaService->asignarUsuario($nota, $validated['usuario']);

        return redirect()
            ->route('admin.cotizaciones.index')
            ->with('success', 'Cotización asignada a '.$validated['usuario'].'.');
    }

    public function exportSinCodigoSoftland(Request $request): StreamedResponse|RedirectResponse
    {
        if (! $this->listadoService->puedeGestionar($request->user())) {
            abort(403);
        }

        if ($this->exportService->productosSinCodigoSoftland()->isEmpty()) {
            return redirect()
                ->route('admin.cotizaciones.index')
                ->with(
                    'warning',
                    'No hay productos sin código Softland en cotizaciones aceptadas. '
                    .'Use el botón Aceptar en la cotización y vuelva a descargar.',
                );
        }

        return $this->exportService->respuestaSinCodigoSoftlandTxt($request->user()->username);
    }

    public function exportAceptadas(Request $request): StreamedResponse
    {
        if (! $this->listadoService->puedeGestionar($request->user())) {
            abort(403);
        }

        return $this->exportService->respuestaAceptadasCsv();
    }

    private function notaGestionable(Request $request, int $nronota): Nota
    {
        $nota = Nota::query()->findOrFail($nronota);

        if (! $this->listadoService->puedeGestionar($request->user())) {
            abort(403);
        }

        if (! $this->listadoService->puedeVer($request->user(), $nota)) {
            abort(403);
        }

        return $nota;
    }

    private function volverListado(Request $request): RedirectResponse
    {
        $query = array_filter([
            'fechadesde' => $request->input('fechadesde'),
            'fechahasta' => $request->input('fechahasta'),
            'nronota' => $request->input('nronota'),
            'cotizacion' => $request->input('cotizacion'),
            'orden_campo' => $request->input('orden_campo'),
            'orden_dir' => $request->input('orden_dir'),
            'page' => $request->input('page'),
        ], fn ($v) => $v !== null && $v !== '');

        return redirect()->route('admin.cotizaciones.index', $query);
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

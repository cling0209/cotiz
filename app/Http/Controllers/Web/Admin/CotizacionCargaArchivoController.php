<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\CotizacionCargaArchivoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use RuntimeException;

class CotizacionCargaArchivoController extends Controller
{
    public function __construct(
        protected CotizacionCargaArchivoService $cargaService,
    ) {}

    public function index(Request $request): View
    {
        return view('admin.cotizaciones.carga-archivo', [
            'nronotaResultado' => session('carga_archivo_nronota'),
        ]);
    }

    public function previsualizar(Request $request): RedirectResponse|View
    {
        $request->validate([
            'archivo' => ['required', 'file'],
        ]);

        try {
            $archivo = $request->file('archivo');
            $this->cargaService->validarArchivo($archivo);

            $rutaAbs = $archivo->getRealPath();
            if ($rutaAbs === false || ! is_readable($rutaAbs)) {
                throw new RuntimeException('No se pudo leer el archivo subido');
            }

            $datos = $this->cargaService->parseCsv($rutaAbs);

            $this->cargaService->validarEstructura($datos);
            $nronotaExistente = $this->cargaService->validarResumen($datos['resumen'], $request->user()->username);

            $previewDetalle = $this->cargaService->buildPreviewDetalle($datos['detalle']);
            $token = uniqid('preview_', true);

            $sessionData = [
                'resumen' => $datos['resumen'],
                'detalle' => $datos['detalle'],
                'nronota_existente' => $nronotaExistente,
            ];

            $previewBag = session('preview_cotizacion', []);
            $previewBag[$token] = $sessionData;
            session(['preview_cotizacion' => $previewBag]);

            $payload = base64_encode(json_encode([
                'resumen' => $datos['resumen'],
                'detalle' => $datos['detalle'],
            ], JSON_UNESCAPED_UNICODE));

            session()->flash('success', 'Previsualización generada. Revise y confirme para cargar.');

            return view('admin.cotizaciones.carga-archivo', [
                'preview' => [
                    'token' => $token,
                    'resumen' => $datos['resumen'],
                    'detalle' => $previewDetalle,
                    'payload' => $payload,
                    'nronota_existente' => $nronotaExistente,
                ],
                'nronotaResultado' => session('carga_archivo_nronota'),
            ]);
        } catch (RuntimeException $e) {
            return redirect()
                ->route('admin.cotizaciones.carga-archivo.index')
                ->with('error', $e->getMessage());
        }
    }

    public function confirmar(Request $request): RedirectResponse
    {
        $token = trim((string) $request->input('previewToken', ''));
        $payload = trim((string) $request->input('previewPayload', ''));

        $datos = null;
        $nronotaExistente = null;

        $previewBag = session('preview_cotizacion', []);
        if ($token !== '' && isset($previewBag[$token])) {
            $datos = $previewBag[$token];
            $nronotaExistente = $datos['nronota_existente'] ?? null;
            unset($previewBag[$token]);
            session(['preview_cotizacion' => $previewBag]);
        }

        if ($datos === null) {
            $decoded = $this->cargaService->decodificarPayload($payload);
            if ($decoded !== null) {
                $datos = $decoded;
            }
        }

        if ($datos === null || ! isset($datos['resumen'], $datos['detalle'])) {
            return redirect()
                ->route('admin.cotizaciones.carga-archivo.index')
                ->with('error', 'No se encontró la previsualización o expiró. Vuelva a previsualizar el archivo.');
        }

        try {
            $resultado = $this->cargaService->guardar(
                [
                    'resumen' => $datos['resumen'],
                    'detalle' => is_array($datos['detalle']) ? $datos['detalle'] : [],
                ],
                $request->user()->username,
                is_numeric($nronotaExistente ?? null) ? (int) $nronotaExistente : null,
            );

            $mensaje = 'Cotización cargada exitosamente. Se guardaron '.$resultado['detalle_count'].' productos';
            if ($resultado['detalle_omitidos'] > 0) {
                $mensaje .= ' ('.$resultado['detalle_omitidos'].' omitidos por no cumplir validaciones)';
            }
            $mensaje .= '. Número de cotización: '.$resultado['nronota'];

            $redirect = redirect()
                ->route('admin.cotizaciones.carga-archivo.index')
                ->with('success', $mensaje)
                ->with('carga_archivo_nronota', $resultado['nronota']);

            if ($resultado['errores_validacion'] !== []) {
                $redirect->with(
                    'warning',
                    'Productos omitidos: '.implode(' · ', array_slice($resultado['errores_validacion'], 0, 5))
                    .(count($resultado['errores_validacion']) > 5 ? ' …' : ''),
                );
            }

            return $redirect;
        } catch (RuntimeException $e) {
            return redirect()
                ->route('admin.cotizaciones.carga-archivo.index')
                ->with('error', $e->getMessage());
        }
    }

    public function plantilla(): Response
    {
        return response($this->cargaService->contenidoPlantilla(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="formato_carga_cotizacion.csv"',
        ]);
    }
}

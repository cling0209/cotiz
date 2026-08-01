<?php

namespace App\Http\Controllers\Web\Admin;

use App\Enums\VinculoOrigen;
use App\Http\Controllers\Controller;
use App\Models\Nota;
use App\Models\User;
use App\Services\CotizacionExportService;
use App\Services\NotaAuditoriaService;
use App\Services\NotaDetalleService;
use App\Services\NotaService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CotizacionExportController extends Controller
{
    public function __construct(
        protected CotizacionExportService $exportService,
        protected NotaDetalleService $detalleService,
        protected NotaService $notaService,
        protected NotaAuditoriaService $auditoria,
    ) {}

    public function pdf(Request $request, int $nronota)
    {
        $nota = $this->notaAutorizada($request, $nronota);

        $gemela = $this->notaService->cotizacionGemela($nota);
        if ($gemela !== null) {
            return response(
                sprintf(
                    'La cotización #%d tiene los mismos productos, cantidades y precios que la #%d, '
                    ."que ya usa el mismo código «%s».\n\n"
                    .'Cambie algún precio o cantidad antes de generar el PDF: subir dos ofertas '
                    .'idénticas al mismo proceso no aporta nada.',
                    $nota->nronota,
                    $gemela->nronota,
                    trim((string) $nota->encargado),
                ),
                422,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        $this->detalleService->confirmarAprendizajeDeNota(
            $nota,
            $request->user()?->username,
            VinculoOrigen::PDF,
        );

        $this->auditoria->registrarPdf($nota, $request->user()?->username);

        $datos = $this->exportService->datosPdf($nota);

        return Pdf::loadView('exports.cotizacion-pdf', $datos)
            ->setPaper('letter', 'portrait')
            ->setOption('isRemoteEnabled', true)
            ->setOption('margin-top', 8)
            ->setOption('margin-right', 8)
            ->setOption('margin-bottom', 8)
            ->setOption('margin-left', 8)
            ->download('cotizacion_'.$nota->nronota.'.pdf');
    }

    public function archivo(Request $request, int $nronota): StreamedResponse
    {
        $nota = $this->notaAutorizada($request, $nronota);

        return $this->exportService->respuestaSoftlandTxt($nota);
    }

    public function excel(Request $request, int $nronota): StreamedResponse
    {
        $nota = $this->notaAutorizada($request, $nronota);

        return $this->exportService->respuestaExcel($nota);
    }

    public function guia(Request $request, int $nronota): StreamedResponse
    {
        $nota = $this->notaAutorizada($request, $nronota);

        return $this->exportService->respuestaGuiaTxt($nota);
    }

    public function guiaIngreso(Request $request, int $nronota): StreamedResponse
    {
        $nota = $this->notaAutorizada($request, $nronota);

        return $this->exportService->respuestaGuiaIngresoCsv($nota);
    }

    private function notaAutorizada(Request $request, int $nronota): Nota
    {
        $nota = $this->exportService->cargarNota($nronota);

        if (! $this->puedeVer($request, $nota)) {
            abort(403);
        }

        if ($nota->requiereNumeroCotizacion()) {
            abort(422, 'Debe ingresar el número de cotización antes de continuar.');
        }

        return $nota;
    }

    private function puedeVer(Request $request, Nota $nota): bool
    {
        $user = $request->user();

        if ($user->perfil === User::PERFIL_SUPERADMIN) {
            return true;
        }

        return $nota->usuario === $user->username;
    }
}

<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Nota;
use App\Models\User;
use App\Services\CompraAgilApiService;
use App\Services\CompraAgilImportService;
use App\Services\CompraAgilOportunidadService;
use App\Services\CompraAgilPayloadMapper;
use App\Services\CompraAgilRegionScope;
use App\Services\NotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CompraAgilBusquedaController extends Controller
{
    public function __construct(
        protected CompraAgilApiService $api,
        protected CompraAgilOportunidadService $oportunidad,
        protected CompraAgilPayloadMapper $mapper,
        protected CompraAgilImportService $importService,
        protected NotaService $notaService,
    ) {}

    public function buscar(Request $request, int $nronota): JsonResponse
    {
        $nota = $this->notaAutorizada($request, $nronota);

        if (! $this->api->isConfigured()) {
            return response()->json([
                'error' => 'API Mercado Público no configurada. Use pegar texto o solicite MERCADOPUBLICO_TICKET al administrador.',
            ], 503);
        }

        $datos = $request->validate([
            'modo' => ['required', 'in:oportunidad,texto,codigo'],
            'q' => ['required_if:modo,texto', 'nullable', 'string', 'max:200'],
            'codigo' => ['required_if:modo,codigo', 'nullable', 'string', 'max:40'],
            'region' => ['required_if:modo,texto', 'nullable', 'integer', 'min:1', 'max:16'],
            'numero_pagina' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            if ($datos['modo'] === 'codigo') {
                $codigo = strtoupper(trim((string) ($datos['codigo'] ?? '')));
                if ($codigo === '') {
                    return response()->json(['error' => 'Indique el número de cotización Compra Ágil.'], 422);
                }

                if ($error = $this->notaService->validarNumeroCotizacionDisponible($nota, $codigo, true)) {
                    return response()->json(['error' => $error], 422);
                }

                $item = $this->oportunidad->enriquecerResumen(
                    $this->oportunidad->detalleResumen($codigo),
                );

                return response()->json([
                    'items' => [$item],
                    'paginacion' => ['total_resultados' => 1, 'numero_pagina' => 1, 'total_paginas' => 1],
                ]);
            }

            $resultado = $this->oportunidad->listarPublicadas([
                'modo' => $datos['modo'],
                'q' => $datos['q'] ?? '',
                'region' => $datos['region'] ?? null,
                'numero_pagina' => $datos['numero_pagina'] ?? 1,
            ]);

            return response()->json($resultado);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function previewCodigo(Request $request, int $nronota): JsonResponse
    {
        $nota = $this->notaAutorizada($request, $nronota);

        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:40'],
            'desde' => ['nullable', 'integer', 'min:0'],
            'hasta' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $codigo = strtoupper(trim($datos['codigo']));
            if ($error = $this->notaService->validarNumeroCotizacionDisponible($nota, $codigo, true)) {
                return response()->json(['error' => $error], 422);
            }

            $payload = $this->api->detalle($codigo);
            if (CompraAgilRegionScope::debeExcluirItem($payload)) {
                return response()->json(['error' => CompraAgilRegionScope::mensajeZonaExcluida()], 422);
            }
            $parseado = $this->mapper->fromDetalle($payload);

            if (isset($datos['desde'], $datos['hasta'])) {
                $resultado = $this->importService->previewLoteDesdeDatos(
                    $parseado,
                    (int) $datos['desde'],
                    (int) $datos['hasta'],
                );
            } else {
                $resultado = $this->importService->previewDesdeDatos($parseado);
            }

            return response()->json($this->adjuntarValidacionCabecera($nota, $resultado, (int) ($datos['desde'] ?? 0)));
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function importarCodigo(Request $request, int $nronota): JsonResponse
    {
        $nota = $this->notaAutorizada($request, $nronota);

        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:40'],
            'desde' => ['nullable', 'integer', 'min:0'],
            'hasta' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $codigo = strtoupper(trim($datos['codigo']));
            if ($error = $this->notaService->validarNumeroCotizacionDisponible($nota, $codigo, true)) {
                return response()->json(['error' => $error], 422);
            }

            $payload = $this->api->detalle($codigo);
            if (CompraAgilRegionScope::debeExcluirItem($payload)) {
                return response()->json(['error' => CompraAgilRegionScope::mensajeZonaExcluida()], 422);
            }
            $parseado = $this->mapper->fromDetalle($payload);

            if ($nota->requiereNumeroCotizacion() && $parseado['cabecera']['codigo_cotizacion'] === '') {
                return response()->json(['error' => 'La Compra Ágil no trae código de cotización.'], 422);
            }

            if (isset($datos['desde'], $datos['hasta'])) {
                $resultado = $this->importService->aplicarLoteDesdeDatos(
                    $nota,
                    $parseado,
                    $request->user()->username,
                    (int) $datos['desde'],
                    (int) $datos['hasta'],
                );
            } else {
                $resultado = $this->importService->aplicarDesdeDatos(
                    $nota,
                    $parseado,
                    $request->user()->username,
                );
            }
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Error interno al importar desde API.'], 500);
        }

        return response()->json(array_merge(['ok' => true], $resultado));
    }

    public function validarCodigo(Request $request, int $nronota): JsonResponse
    {
        $nota = $this->notaAutorizada($request, $nronota);

        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:40'],
        ]);

        $codigo = strtoupper(trim($datos['codigo']));
        if ($codigo === '') {
            return response()->json(['error' => 'Indique el número de cotización Compra Ágil.'], 422);
        }

        if ($error = $this->notaService->validarNumeroCotizacionDisponible($nota, $codigo, true)) {
            return response()->json(['error' => $error], 422);
        }

        return response()->json(['ok' => true, 'codigo' => $codigo]);
    }

    /**
     * @param  array<string, mixed>  $resultado
     * @return array<string, mixed>
     */
    private function adjuntarValidacionCabecera(Nota $nota, array $resultado, int $desde): array
    {
        $errorCabecera = null;
        $puedeImportar = true;

        if ($desde === 0 && ($resultado['cabecera']['codigo_cotizacion'] ?? '') !== '') {
            $errorCabecera = $this->notaService->validarNumeroCotizacionDisponible(
                $nota,
                $resultado['cabecera']['codigo_cotizacion'],
                true,
            );
            if ($errorCabecera !== null) {
                $puedeImportar = false;
            }
        }

        return array_merge($resultado, [
            'error_cabecera' => $errorCabecera,
            'puede_importar' => $puedeImportar,
            'codigo_api' => $resultado['cabecera']['codigo_cotizacion'] ?? '',
        ]);
    }

    private function notaAutorizada(Request $request, int $nronota): Nota
    {
        $nota = Nota::query()->findOrFail($nronota);
        $user = $request->user();

        if ($user->perfil !== User::PERFIL_SUPERADMIN && $nota->usuario !== $user->username) {
            abort(403);
        }

        return $nota;
    }
}

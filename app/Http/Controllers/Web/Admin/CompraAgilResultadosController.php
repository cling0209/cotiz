<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\NotaMpResultadosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class CompraAgilResultadosController extends Controller
{
    public function __construct(
        protected NotaMpResultadosService $resultados,
    ) {}

    public function index(Request $request): View
    {
        $corridaEnCurso = $this->resultados->corridaEnCurso();

        return view('admin.compra-agil.resultados', [
            'apiConfigurada' => $this->resultados->apiConfigurada(),
            'ultimaCorrida' => $this->resultados->ultimaCorrida(),
            'corridaEnCurso' => $corridaEnCurso,
            'estadoCorrida' => $this->resultados->estadoCorrida($corridaEnCurso),
            'novedades' => $this->resultados->novedadesUltimaCorrida(),
            'detalleCorrida' => $this->resultados->detalleUltimaCorrida(),
            'cerradasCount' => $this->resultados->contarCerradas(),
            'pendientesCount' => $this->resultados->contarNotasPendientesConsulta(),
            'limiteCorridaMax' => $this->resultados->limiteCorridaMax(),
        ]);
    }

    public function resultado(): View
    {
        $ultimaCorrida = $this->resultados->ultimaCorrida();

        return view('admin.compra-agil.resultados-resultado', [
            'ultimaCorrida' => $ultimaCorrida,
            'detalleCorrida' => $this->resultados->detalleUltimaCorrida(),
        ]);
    }

    public function cerradas(Request $request): View
    {
        $filtros = $request->only(['nronota', 'organismo', 'fecha_desde', 'fecha_hasta']);

        return view('admin.compra-agil.resultados-cerradas', [
            'cerradas' => $this->resultados->listadoCerradasPaginado(20, $filtros),
            'filtros' => $filtros,
        ]);
    }

    public function iniciar(Request $request): JsonResponse
    {
        if (! $this->resultados->apiConfigurada()) {
            return response()->json(['error' => 'Configure MERCADOPUBLICO_TICKET.'], 503);
        }

        $enCurso = $this->resultados->corridaEnCurso();
        if ($enCurso !== null) {
            return response()->json([
                'error' => 'Ya hay una consulta en curso. Espere a que finalice o vuelva a esta pantalla para ver el progreso.',
                'estado' => $this->resultados->estadoCorrida($enCurso),
            ], 409);
        }

        $limite = $this->resultados->normalizarLimiteConsulta((int) $request->input('limite', 5));

        try {
            $this->resultados->encolarCorrida((string) $request->user()->username, $limite);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'estado' => $this->resultados->estadoCorrida(),
        ]);
    }

    public function estado(Request $request): JsonResponse
    {
        return response()->json($this->resultados->estadoCorrida());
    }

    public function cancelar(Request $request): JsonResponse
    {
        try {
            $this->resultados->cancelarCorridaEnCurso((string) $request->user()->username);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'estado' => $this->resultados->estadoCorrida(),
        ]);
    }

    public function detalle(int $nronota): JsonResponse
    {
        try {
            $detalle = $this->resultados->detalleNota($nronota);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }

        $seg = $detalle['seguimiento'];

        return response()->json([
            'seguimiento' => [
                'nronota' => $seg->nronota,
                'codigo_proceso' => $seg->codigo_proceso,
                'estado_mp_codigo' => $seg->estado_mp_codigo,
                'estado_mp_glosa' => $seg->estado_mp_glosa,
                'organismo' => $seg->organismo,
                'rut_ganador' => $seg->rut_ganador,
                'razon_social_ganador' => $seg->razon_social_ganador,
                'resultado_propio' => $seg->resultado_propio,
                'finalizado' => $seg->finalizado,
                'monto_total_ganador' => $seg->monto_total_ganador,
                'id_orden_compra' => $seg->id_orden_compra,
                'fecha_publicacion' => $seg->fecha_publicacion?->toIso8601String(),
                'fecha_cierre' => $seg->fecha_cierre?->toIso8601String(),
                'fecha_ultimo_cambio' => $seg->fecha_ultimo_cambio?->toIso8601String(),
                'fecha_cancelacion' => $seg->fecha_cancelacion?->toIso8601String(),
            ],
            'ofertas' => $detalle['ofertas']->map(fn ($o) => [
                'id' => $o->id,
                'rut_proveedor' => $o->rut_proveedor,
                'razon_social' => $o->razon_social,
                'proveedor_seleccionado' => $o->proveedor_seleccionado,
                'monto_total' => $o->monto_total,
                'es_propio' => $o->es_propio,
                'inadmisible' => $o->inadmisible,
                'lineas' => $o->lineas->map(fn ($l) => [
                    'codigo_producto' => $l->codigo_producto ?: null,
                    'descripcion' => $l->descripcion ?: $l->nombre_producto,
                    'cantidad' => $l->cantidad,
                    'precio_unitario' => $l->precio_unitario,
                    'monto_total' => $l->monto_total,
                ]),
            ]),
        ]);
    }
}

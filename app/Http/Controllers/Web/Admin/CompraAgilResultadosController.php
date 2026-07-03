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
            'cerradas' => $this->resultados->listadoCerradas(100),
            'pendientesCount' => $this->resultados->contarNotasPendientesConsulta(),
            'limiteCorridaMax' => $this->resultados->limiteCorridaMax(),
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
                    'descripcion' => $l->descripcion ?: $l->nombre_producto,
                    'cantidad' => $l->cantidad,
                    'precio_unitario' => $l->precio_unitario,
                    'monto_total' => $l->monto_total,
                ]),
            ]),
        ]);
    }
}

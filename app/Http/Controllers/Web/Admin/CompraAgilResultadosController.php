<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotaMpCorrida;
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
        return view('admin.compra-agil.resultados', [
            'apiConfigurada' => $this->resultados->apiConfigurada(),
            'ultimaCorrida' => $this->resultados->ultimaCorrida(),
            'corridaEnCurso' => $this->resultados->corridaEnCurso(),
            'kpi' => $this->resultados->resumenEstadistica(),
            'novedades' => $this->resultados->novedadesUltimaCorrida(),
            'cerradas' => $this->resultados->listadoCerradas(100),
            'pendientesCount' => $this->resultados->notasPendientesConsulta()->count(),
        ]);
    }

    public function iniciar(Request $request): JsonResponse
    {
        if (! $this->resultados->apiConfigurada()) {
            return response()->json(['error' => 'Configure MERCADOPUBLICO_TICKET.'], 503);
        }

        $pendientes = $this->resultados->notasPendientesConsulta();
        if ($pendientes->isEmpty()) {
            return response()->json([
                'error' => 'No hay cotizaciones pendientes de consultar (sin código CA o ya finalizadas).',
            ], 422);
        }

        $corrida = $this->resultados->iniciarCorrida((string) $request->user()->username);

        return response()->json([
            'ok' => true,
            'corrida_id' => $corrida->id,
            'usuario' => $corrida->usuario,
            'inicio' => $corrida->inicio->format('d/m/Y H:i:s'),
            'pendientes' => $pendientes->values(),
            'total' => $pendientes->count(),
        ]);
    }

    public function consultar(Request $request, int $nronota): JsonResponse
    {
        $datos = $request->validate([
            'corrida_id' => ['required', 'integer', 'exists:nota_mp_corridas,id'],
        ]);

        $corrida = NotaMpCorrida::query()->findOrFail((int) $datos['corrida_id']);
        if ($corrida->estado !== 'running') {
            return response()->json(['error' => 'La corrida no está activa.'], 422);
        }

        try {
            $resultado = $this->resultados->consultarNota(
                $nronota,
                $corrida,
                (string) $request->user()->username,
            );
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'resultado' => $resultado]);
    }

    public function finalizar(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'corrida_id' => ['required', 'integer', 'exists:nota_mp_corridas,id'],
            'estado' => ['nullable', 'in:ok,error,cancelled'],
            'mensaje' => ['nullable', 'string', 'max:500'],
        ]);

        $corrida = NotaMpCorrida::query()->findOrFail((int) $datos['corrida_id']);
        if ($corrida->estado !== 'running') {
            return response()->json(['ok' => true, 'corrida' => $corrida]);
        }

        $corrida = $this->resultados->finalizarCorrida(
            $corrida,
            $datos['estado'] ?? 'ok',
            $datos['mensaje'] ?? null,
        );

        return response()->json([
            'ok' => true,
            'corrida' => [
                'id' => $corrida->id,
                'usuario' => $corrida->usuario,
                'inicio' => $corrida->inicio->format('d/m/Y H:i:s'),
                'fin' => $corrida->fin?->format('d/m/Y H:i:s'),
                'notas_procesadas' => $corrida->notas_procesadas,
                'notas_con_cambio' => $corrida->notas_con_cambio,
                'estado' => $corrida->estado,
            ],
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

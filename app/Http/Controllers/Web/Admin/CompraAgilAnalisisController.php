<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Support\ListadoPorPagina;
use App\Services\CompraAgilCompetenciaService;
use App\Services\CompraAgilSyncService;
use App\Services\NotaMpResultadosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompraAgilAnalisisController extends Controller
{
    public function __construct(
        protected CompraAgilCompetenciaService $competencia,
        protected CompraAgilSyncService $sync,
        protected NotaMpResultadosService $resultados,
    ) {}

    public function index(Request $request): View
    {
        $filtros = $this->filtros($request);

        return view('admin.compra-agil.analisis', [
            'filtros' => $filtros,
            'kpi' => $this->competencia->resumen($filtros),
            'productos' => $this->competencia->listado($filtros),
        ]);
    }

    public function sincronizar(Request $request): RedirectResponse|JsonResponse
    {
        $resultado = $this->sync->sincronizarAdjudicadas(
            usuario: (string) $request->user()->username,
        );

        if ($request->expectsJson()) {
            if ($resultado['error']) {
                return response()->json(['error' => $resultado['error']], 422);
            }

            return response()->json(['ok' => true, 'resultado' => $resultado]);
        }

        if ($resultado['error']) {
            return back()->with('error', 'Sync fallida: '.$resultado['error']);
        }

        $mensaje = sprintf(
            'Sync OK: %d listados, %d códigos encontrados, %d detalles, %d procesos nuevos.',
            $resultado['listados'],
            $resultado['codigos_encontrados'] ?? 0,
            $resultado['detalles'],
            $resultado['procesos_nuevos'],
        );

        if (($resultado['codigos_encontrados'] ?? 0) === 0) {
            return back()->with('warning', $mensaje.' No hubo adjudicadas en el período consultado. Revise MERCADOPUBLICO_SYNC_DIAS o el ticket.');
        }

        if ($resultado['detalles'] === 0 && ($resultado['codigos_encontrados'] ?? 0) > 0) {
            return back()->with('warning', $mensaje.' Los procesos ya estaban sincronizados.');
        }

        return back()->with('success', $mensaje);
    }

    public function exportarExcel(Request $request): StreamedResponse
    {
        return $this->competencia->exportarExcel($this->filtros($request));
    }

    public function detallePrecios(Request $request, string $prodItem): JsonResponse
    {
        $detalle = $this->competencia->detallePrecios($prodItem, $this->filtros($request));
        if ($detalle === null) {
            return response()->json(['error' => 'No hay una nota en la que hayas ofertado junto con otra empresa.'], 404);
        }

        return response()->json($detalle);
    }

    public function detalleProducto(Request $request, string $prodItem): JsonResponse
    {
        $detalle = $this->competencia->detalle($prodItem, $this->filtros($request));
        if ($detalle === null) {
            return response()->json(['error' => 'Sin datos para ese producto en el período.'], 404);
        }

        return response()->json($detalle);
    }

    /**
     * Mismo detalle que Resultados → Todas, filtrado al producto de la fila.
     * Usa la última nota cerrada con oferta propia y proveedor adjudicado.
     */
    public function detalleNotaProducto(Request $request, string $prodItem): JsonResponse
    {
        $filtros = $this->filtros($request);
        $nronota = $this->competencia->nronotaUltimaCerrada($prodItem, $filtros);
        if ($nronota === null) {
            return response()->json([
                'error' => 'No hay una nota cerrada donde hayan participado el propio y un adjudicado.',
            ], 404);
        }

        try {
            $detalle = $this->resultados->detalleNota($nronota);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }

        $ordenes = $this->competencia->ordenesProductoEnNota($prodItem, $nronota);
        $seg = $detalle['seguimiento'];

        return response()->json([
            'prod_item_filtro' => trim($prodItem),
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
                'ocompra' => trim((string) ($seg->nota?->ocompra ?? '')) ?: null,
                'orden_compra' => $seg->valorOrdenCompraExport() ?: null,
                'es_ganador_grupo' => $seg->esGanadorGrupo(),
                'fecha_publicacion' => $seg->fecha_publicacion?->toIso8601String(),
                'fecha_cierre' => $seg->fecha_cierre?->toIso8601String(),
                'fecha_ultimo_cambio' => $seg->fecha_ultimo_cambio?->toIso8601String(),
                'fecha_cancelacion' => $seg->fecha_cancelacion?->toIso8601String(),
                'convocatoria_estado' => $seg->convocatoria_estado,
                'convocatoria_descripcion' => $seg->convocatoria_descripcion,
                'fecha_cierre_primer_llamado' => $seg->fecha_cierre_primer_llamado?->toIso8601String(),
                'fecha_cierre_segundo_llamado' => $seg->fecha_cierre_segundo_llamado?->toIso8601String(),
            ],
            'ofertas' => $detalle['ofertas']->map(function ($o) use ($ordenes) {
                $lineasOrdenadas = $o->lineas->sortBy('id')->values();
                $lineas = $this->competencia->filtrarLineasPorOrdenes($lineasOrdenadas, $ordenes);

                return [
                    'id' => $o->id,
                    'rut_proveedor' => $o->rut_proveedor,
                    'razon_social' => $o->razon_social,
                    'proveedor_seleccionado' => $o->proveedor_seleccionado,
                    'monto_total' => $o->monto_total,
                    'es_propio' => $o->es_propio,
                    'inadmisible' => $o->inadmisible,
                    'lineas' => collect($lineas)->map(fn ($l) => [
                        'codigo_producto' => $l->codigo_producto ?: null,
                        'descripcion' => $l->descripcion ?: $l->nombre_producto,
                        'cantidad' => $l->cantidad,
                        'precio_unitario' => $l->precio_unitario,
                        'monto_total' => $l->monto_total,
                    ])->values(),
                ];
            }),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function filtros(Request $request): array
    {
        $orden = (string) $request->query('orden', 'cant_total');
        if (! in_array($orden, ['cant_total', 'adjudicada_propia', 'adjudicada_otros', 'nadie_gano'], true)) {
            $orden = 'cant_total';
        }
        $dir = (string) $request->query('dir', 'desc');
        if (! in_array($dir, ['asc', 'desc'], true)) {
            $dir = 'desc';
        }

        return [
            'buscar' => trim((string) $request->query('buscar', '')),
            'fecha_desde' => trim((string) $request->query('fecha_desde', '')),
            'fecha_hasta' => trim((string) $request->query('fecha_hasta', '')),
            'orden' => $orden,
            'dir' => $dir,
            'page' => $request->integer('page', 1),
            'por_pagina' => ListadoPorPagina::resolver($request, 'compra-agil-analisis'),
        ];
    }
}

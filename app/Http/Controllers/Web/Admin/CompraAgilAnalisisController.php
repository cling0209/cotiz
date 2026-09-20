<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Support\ListadoPorPagina;
use App\Services\CompraAgilCompetenciaService;
use App\Services\CompraAgilSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompraAgilAnalisisController extends Controller
{
    public function __construct(
        protected CompraAgilCompetenciaService $competencia,
        protected CompraAgilSyncService $sync,
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
            return response()->json(['error' => 'Sin precios de otras empresas para ese producto en el período.'], 404);
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

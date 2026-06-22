<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\CompraAgilBenchmarkService;
use App\Services\CompraAgilSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompraAgilAnalisisController extends Controller
{
    public function __construct(
        protected CompraAgilBenchmarkService $benchmark,
        protected CompraAgilSyncService $sync,
    ) {}

    public function index(Request $request): View
    {
        $vista = (string) $request->query('vista', 'vinculados');
        if (! in_array($vista, ['vinculados', 'sin_vinculo'], true)) {
            $vista = 'vinculados';
        }

        $filtros = [
            'vista' => $vista,
            'buscar' => trim((string) $request->query('buscar', '')),
            'solo_alertas' => $request->boolean('solo_alertas'),
            'solo_con_datos' => $request->boolean('solo_con_datos'),
            'orden' => $request->query('orden', $vista === 'sin_vinculo' ? 'procesos_desc' : 'desvio_desc'),
            'page' => $request->integer('page', 1),
        ];

        return view('admin.compra-agil.analisis', [
            'filtros' => $filtros,
            'vista' => $vista,
            'kpi' => $this->benchmark->resumenKpi(),
            'ultimaSync' => $this->benchmark->ultimaSync(),
            'productos' => $vista === 'vinculados' ? $this->benchmark->listadoAdmin($filtros) : null,
            'sinVinculo' => $vista === 'sin_vinculo' ? $this->benchmark->listadoSinVinculo($filtros) : null,
            'diasAnalisis' => (int) config('cotiz.mercadopublico.sync_dias', 30),
            'apiConfigurada' => trim((string) config('cotiz.mercadopublico.ticket', '')) !== '',
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

    public function detalleProducto(Request $request, string $prodItem): JsonResponse
    {
        return response()->json([
            'lineas_mercado' => $this->benchmark->lineasMercadoProducto($prodItem),
            'similares_catalogo' => $this->benchmark->similaresCatalogo($prodItem),
        ]);
    }
}

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
            'novedades' => $this->resultados->novedadesRecientes(),
            'detalleCorrida' => $this->resultados->detalleUltimaCorrida(),
            'cerradasCount' => $this->resultados->contarCerradas(),
            'pendientesCount' => $this->resultados->contarNotasPendientesConsulta(),
        ]);
    }

    public function resultado(): View
    {
        $ultimaCorrida = $this->resultados->ultimaCorrida();

        return view('admin.compra-agil.resultados-resultado', [
            'ultimaCorrida' => $ultimaCorrida,
            'detalleCorrida' => $this->resultados->detalleUltimaCorridaPaginado(50),
        ]);
    }

    public function cerradas(Request $request): View
    {
        $filtros = $request->only([
            'nronota', 'codigo_proceso', 'organismo', 'proveedor',
            'fecha_desde', 'fecha_hasta', 'cambio_desde', 'cambio_hasta',
        ]);

        return view('admin.compra-agil.resultados-cerradas', [
            'cerradas' => $this->resultados->listadoCerradasPaginado(20, $filtros),
            'filtros' => $filtros,
        ]);
    }

    public function cerradasExportar(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filtros = $request->only([
            'nronota', 'codigo_proceso', 'organismo', 'proveedor',
            'fecha_desde', 'fecha_hasta', 'cambio_desde', 'cambio_hasta',
        ]);
        $cerradas = $this->resultados->listadoCerradasExportar($filtros);
        $filename = 'cerradas_compra_agil_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($cerradas) {
            $out = fopen('php://output', 'w');
            fprintf($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Nota',
                'Código CA',
                'Publicación',
                'Último cambio',
                'Organismo',
                'Estado MP',
                'Seguimiento',
                'Prov. seleccionado',
                'RUT ganador',
                'Monto',
                'Ejecutivo',
                'Cliente',
                'Consultado',
                'Propio',
                'OC',
            ], ';');
            foreach ($cerradas as $seg) {
                fputcsv($out, [
                    $seg->nronota,
                    $seg->codigo_proceso,
                    $seg->fecha_publicacion?->format('d/m/Y H:i') ?? '',
                    $seg->fecha_ultimo_cambio?->format('d/m/Y H:i') ?? '',
                    $seg->organismo,
                    $seg->estado_mp_glosa ?: $seg->estado_mp_codigo,
                    $seg->resultado_propio,
                    $seg->razon_social_ganador,
                    $seg->rut_ganador,
                    $seg->monto_total_ganador,
                    $this->resultados->nombreEjecutivoNota($seg),
                    $seg->nota?->empresa,
                    $seg->ultimo_consultado_en?->format('d/m/Y H:i') ?? '',
                    ! empty($seg->es_ganador_propio) ? 'Sí' : 'No',
                    $seg->id_orden_compra,
                ], ';');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function analisisPrecios(Request $request): View
    {
        $filtros = $request->only(['producto', 'nronota', 'codigo_proceso', 'proveedor', 'fecha_desde', 'fecha_hasta', 'precio_desde', 'precio_hasta', 'solo_ganador']);

        if (! $request->has('solo_ganador') && ! $request->hasAny(['producto', 'nronota', 'codigo_proceso', 'proveedor', 'fecha_desde', 'fecha_hasta'])) {
            $filtros['solo_ganador'] = '1';
        }

        return view('admin.compra-agil.resultados-analisis-precios', [
            'lineas' => ! empty(array_filter(collect($filtros)->except('solo_ganador')->all()))
                ? $this->resultados->analisisPrecios($filtros)
                : null,
            'filtros' => $filtros,
        ]);
    }

    public function analisisPreciosExportar(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filtros = $request->only(['producto', 'nronota', 'codigo_proceso', 'proveedor', 'fecha_desde', 'fecha_hasta', 'precio_desde', 'precio_hasta', 'solo_ganador']);
        $lineas = $this->resultados->analisisPreciosExportar($filtros);

        $filename = 'analisis_precios_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($lineas) {
            $out = fopen('php://output', 'w');
            fprintf($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Código', 'Producto', 'Descripción', 'P.Unitario', 'Cantidad', 'Total',
                'Nota', 'Código CA', 'Publicación', 'Organismo', 'Proveedor', 'RUT',
                'Prov. seleccionado', 'Propio', 'Dif.%', 'P.Unit. Romulo', 'Cant. Romulo', 'Total Romulo',
            ], ';');
            foreach ($lineas as $l) {
                $diffPct = '';
                if ($l->precio_propio !== null && $l->precio_unitario > 0) {
                    $diffPct = round(($l->precio_propio - $l->precio_unitario) / $l->precio_unitario * 100, 1);
                }
                fputcsv($out, [
                    $l->codigo_producto,
                    $l->nombre_producto,
                    $l->descripcion,
                    $l->precio_unitario,
                    $l->cantidad,
                    $l->monto_total,
                    $l->nronota,
                    $l->codigo_proceso,
                    $l->fecha_publicacion ? \Carbon\Carbon::parse($l->fecha_publicacion)->format('d/m/Y') : '',
                    $l->organismo,
                    $l->razon_social,
                    $l->rut_proveedor,
                    $l->proveedor_seleccionado ? 'Sí' : 'No',
                    $l->es_propio ? 'Sí' : 'No',
                    $diffPct,
                    $l->precio_propio,
                    $l->cantidad_propia !== null ? (int) $l->cantidad_propia : '',
                    $l->total_propio,
                ], ';');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
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

        try {
            $this->resultados->encolarCorrida((string) $request->user()->username);
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
                'convocatoria_estado' => $seg->convocatoria_estado,
                'convocatoria_descripcion' => $seg->convocatoria_descripcion,
                'fecha_cierre_primer_llamado' => $seg->fecha_cierre_primer_llamado?->toIso8601String(),
                'fecha_cierre_segundo_llamado' => $seg->fecha_cierre_segundo_llamado?->toIso8601String(),
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

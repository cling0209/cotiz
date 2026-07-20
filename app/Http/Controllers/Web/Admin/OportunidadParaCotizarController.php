<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\CompraAgilRegionScope;
use App\Services\OportunidadBusquedaService;
use App\Services\OportunidadEncontradaRelayService;
use App\Services\OportunidadParaCotizarService;
use App\Services\OportunidadVinculoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class OportunidadParaCotizarController extends Controller
{
    public function __construct(
        protected OportunidadParaCotizarService $servicio,
        protected OportunidadBusquedaService $busqueda,
        protected OportunidadVinculoService $vinculos,
        protected OportunidadEncontradaRelayService $encontradaRelay,
    ) {}

    public function index(Request $request): View
    {
        $puedeBuscar = (bool) $request->user()?->canAccessOportunidades();
        $puedePalabras = (bool) $request->user()?->canAccessPalabrasClave();
        $palabras = ($puedeBuscar || $puedePalabras) ? $this->servicio->palabrasClave() : [];
        $userId = (int) ($request->user()?->id ?? 0);
        $guardadas = $this->servicio->listarGuardadasVigentesDesde(null, $userId > 0 ? $userId : null);
        $corridaEstado = $puedeBuscar ? $this->busqueda->estado() : null;

        $regionesFiltro = [];
        foreach (CompraAgilRegionScope::regionesIncluidas() as $codigoRegion) {
            $regionesFiltro[(int) $codigoRegion] = CompraAgilRegionScope::nombreRegion((int) $codigoRegion);
        }

        return view('admin.oportunidades.para-cotizar.index', [
            'palabras' => $palabras,
            'guardadas' => $guardadas,
            'puedeBuscar' => $puedeBuscar,
            'puedePalabras' => $puedePalabras,
            'fechaBusqueda' => is_array($corridaEstado) && ! empty($corridaEstado['fecha_busqueda'])
                ? (string) $corridaEstado['fecha_busqueda']
                : $this->servicio->fechaBusquedaHoy(),
            'apiConfigurada' => true,
            'mpBaseUrl' => rtrim((string) config('cotiz.mercadopublico.base_url'), '/'),
            'mpPath' => '/v2/compra-agil',
            'corridaEstado' => $corridaEstado,
            'vinculoPendientes' => $puedeBuscar ? $this->vinculos->contarPendientesSafe() : 0,
            'regionesFiltro' => $regionesFiltro,
            'filtrosUserId' => $userId,
            'syncPar' => $puedeBuscar ? $this->encontradaRelay->resumenSyncPar() : null,
        ]);
    }

    public function registrarVisita(Request $request): JsonResponse
    {
        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:40'],
        ]);

        $userId = (int) ($request->user()?->id ?? 0);
        if ($userId <= 0) {
            return response()->json(['ok' => false, 'error' => 'No autenticado.'], 401);
        }

        $veces = $this->servicio->registrarVisita($userId, $data['codigo']);

        return response()->json([
            'ok' => true,
            'codigo' => strtoupper(trim($data['codigo'])),
            'visitas_usuario' => $veces,
        ]);
    }

    public function iniciar(Request $request): JsonResponse
    {
        try {
            $corrida = $this->busqueda->iniciar((string) ($request->user()?->username ?? 'sistema'));
        } catch (RuntimeException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'corrida' => $this->busqueda->estado($corrida),
        ]);
    }

    public function estado(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'corrida' => $this->busqueda->estado(),
            'sync_par' => $this->encontradaRelay->resumenSyncPar(),
        ]);
    }

    public function sincronizarPar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tipo' => ['nullable', 'string', 'in:cotizaciones,vinculaciones,all,graba,vinculo'],
        ]);

        try {
            $resultado = $this->encontradaRelay->sincronizarPendientesPorTipo(
                (string) ($data['tipo'] ?? 'all'),
                true,
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
                'sync_par' => $this->encontradaRelay->resumenSyncPar(),
            ], 422);
        }

        return response()->json([
            'ok' => $resultado['ok'],
            'mensaje' => $resultado['mensaje'],
            'pendientes_ok' => $resultado['pendientes_ok'],
            'pendientes_fail' => $resultado['pendientes_fail'],
            'sync_par' => $resultado['sync_par'],
            'corrida' => $this->busqueda->estado(),
        ]);
    }

    public function cancelar(): JsonResponse
    {
        $corrida = $this->busqueda->cancelar();

        return response()->json([
            'ok' => true,
            'corrida' => $corrida !== null ? $this->busqueda->estado($corrida) : null,
        ]);
    }

    public function reanudar(): JsonResponse
    {
        $corrida = $this->busqueda->reanudar();
        if ($corrida === null) {
            return response()->json([
                'ok' => false,
                'error' => 'No hay una búsqueda en curso para retomar.',
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'corrida' => $this->busqueda->estado($corrida),
        ]);
    }

    public function iniciarVinculo(Request $request): JsonResponse
    {
        $fecha = $request->input('fecha_busqueda');
        if ($fecha === null || trim((string) $fecha) === '') {
            $fecha = $this->busqueda->ultimaCorrida()?->fecha_busqueda
                ?? $this->servicio->fechaBusquedaHoy();
        }

        $resultado = $this->vinculos->iniciarConDetalle(
            $fecha,
            (string) ($request->user()?->username ?? 'sistema'),
        );

        if (! $resultado['ok']) {
            return response()->json([
                'ok' => false,
                'error' => $resultado['motivo'] ?? 'No se pudo iniciar la vinculación.',
                'pendientes' => $resultado['pendientes'],
                'corrida' => $this->busqueda->estado(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'pendientes' => $resultado['pendientes'],
            'corrida' => $this->busqueda->estado(),
        ]);
    }

    public function cancelarVinculo(): JsonResponse
    {
        $corrida = $this->vinculos->cancelar();

        return response()->json([
            'ok' => true,
            'corrida' => $this->busqueda->estado(),
            'vinculo' => $corrida !== null ? $this->vinculos->estado($corrida) : null,
        ]);
    }

    public function detalleVinculo(string $codigo): JsonResponse
    {
        $codigo = strtoupper(trim($codigo));
        if ($codigo === '') {
            return response()->json([
                'ok' => false,
                'error' => 'Código vacío.',
            ], 422);
        }

        $preview = $this->vinculos->previewParaDetalle($codigo);
        if ($preview === null) {
            return response()->json([
                'ok' => false,
                'error' => 'No se pudo obtener el detalle de productos de Mercado Público para esta cotización.',
                'codigo' => $codigo,
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'codigo' => $preview['codigo'],
            'lineas' => $preview['lineas'],
            'resumen' => $preview['resumen'],
            'porcentaje_vinculo' => $preview['porcentaje_vinculo'],
            'productos_vinculados' => $preview['productos_vinculados'],
            'cantidad_productos' => $preview['cantidad_productos'],
        ]);
    }

    public function vincularCodigo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:40'],
        ]);

        $resultado = $this->vinculos->asegurarVinculoCodigo($data['codigo']);
        if (! $resultado['ok']) {
            return response()->json([
                'ok' => false,
                'error' => $resultado['error'] ?? 'No se pudo vincular la cotización.',
                'item' => $resultado['item'],
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'ya_estaba' => $resultado['ya_estaba'],
            'total' => $resultado['total'],
            'vinculados' => $resultado['vinculados'],
            'porcentaje' => $resultado['porcentaje'],
            'item' => $resultado['item'],
        ]);
    }

    public function paso(Request $request): JsonResponse
    {
        $data = $request->validate([
            'frase' => ['required', 'string', 'max:200'],
            'region' => ['required', 'integer', 'min:1', 'max:16'],
            'indice' => ['nullable', 'integer', 'min:0'],
            'total_pasos' => ['nullable', 'integer', 'min:0'],
            'codigos_excluidos' => ['nullable', 'array'],
            'codigos_excluidos.*' => ['string', 'max:40'],
        ]);

        $excluidos = array_values(array_filter(array_map(
            static fn ($c) => strtoupper(trim((string) $c)),
            $data['codigos_excluidos'] ?? [],
        )));

        try {
            $resultado = $this->servicio->ejecutarPaso(
                $data['frase'],
                (int) $data['region'],
                $excluidos,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
                'nuevos' => [],
                'guardadas' => 0,
                'consulta' => $this->servicio->consultaDebugPaso($data['frase'], (int) $data['region']),
                'frase' => $data['frase'],
                'region' => (int) $data['region'],
            ], 502);
        }

        $indice = (int) ($data['indice'] ?? 0);
        $total = (int) ($data['total_pasos'] ?? 0);
        $esUltimo = $total > 0 && ($indice + 1) >= $total;
        $fin = $esUltimo ? now()->timezone(config('app.timezone')) : null;

        return response()->json([
            'ok' => true,
            'error' => null,
            'nuevos' => $resultado['items'],
            'guardadas' => $resultado['guardadas'] ?? 0,
            'consulta' => $resultado['consulta'],
            'frase' => $data['frase'],
            'region' => (int) $data['region'],
            'indice' => $indice,
            'total_pasos' => $total,
            'progreso' => $total > 0 ? min(100, (int) round((($indice + 1) / $total) * 100)) : 0,
            'terminado' => $esUltimo,
            'fin' => $fin?->toIso8601String(),
            'fin_label' => $fin?->format('H:i:s'),
        ]);
    }
}

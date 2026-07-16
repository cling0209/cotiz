<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\CompraAgilRegionScope;
use App\Services\OportunidadBusquedaService;
use App\Services\OportunidadParaCotizarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class OportunidadParaCotizarController extends Controller
{
    public function __construct(
        protected OportunidadParaCotizarService $servicio,
        protected OportunidadBusquedaService $busqueda,
    ) {}

    public function index(Request $request): View
    {
        $puedeBuscar = (bool) $request->user()?->canAccessOportunidades();
        $palabras = $puedeBuscar ? $this->servicio->palabrasClave() : [];
        $guardadas = $this->servicio->listarGuardadasHoy();

        $regionesFiltro = [];
        foreach (CompraAgilRegionScope::regionesIncluidas() as $codigoRegion) {
            $regionesFiltro[(int) $codigoRegion] = CompraAgilRegionScope::nombreRegion((int) $codigoRegion);
        }

        return view('admin.oportunidades.para-cotizar.index', [
            'palabras' => $palabras,
            'guardadas' => $guardadas,
            'puedeBuscar' => $puedeBuscar,
            'fechaBusqueda' => $this->servicio->fechaBusquedaHoy(),
            'apiConfigurada' => true,
            'mpBaseUrl' => rtrim((string) config('cotiz.mercadopublico.base_url'), '/'),
            'mpPath' => '/v2/compra-agil',
            'corridaEstado' => $puedeBuscar ? $this->busqueda->estado() : null,
            'regionesFiltro' => $regionesFiltro,
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

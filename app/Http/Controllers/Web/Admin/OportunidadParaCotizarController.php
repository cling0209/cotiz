<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\OportunidadParaCotizarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class OportunidadParaCotizarController extends Controller
{
    public function __construct(
        protected OportunidadParaCotizarService $servicio,
    ) {}

    public function index(): View
    {
        $palabras = $this->servicio->palabrasClave();

        return view('admin.oportunidades.para-cotizar.index', [
            'palabras' => $palabras,
            'apiConfigurada' => true,
            'mpBaseUrl' => rtrim((string) config('cotiz.mercadopublico.base_url'), '/'),
            'mpPath' => '/v2/compra-agil',
        ]);
    }

    public function iniciar(): JsonResponse
    {
        $plan = $this->servicio->planBusqueda();
        $inicio = now()->timezone(config('app.timezone'));

        if ($plan['error'] !== null) {
            return response()->json([
                'ok' => false,
                'error' => $plan['error'],
                'palabras' => $plan['palabras'],
                'pasos' => [],
                'total_pasos' => 0,
                'fecha' => $plan['fecha'],
                'inicio' => $inicio->toIso8601String(),
                'inicio_label' => $inicio->format('H:i:s'),
            ], $plan['api_configurada'] ? 422 : 503);
        }

        return response()->json([
            'ok' => true,
            'error' => null,
            'palabras' => $plan['palabras'],
            'pasos' => $plan['pasos'],
            'total_pasos' => $plan['total_pasos'],
            'fecha' => $plan['fecha'],
            'inicio' => $inicio->toIso8601String(),
            'inicio_label' => $inicio->format('H:i:s'),
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
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
                'nuevos' => [],
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

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\OrganismoObservacionRelayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class OrganismoObservacionApiController extends Controller
{
    public function __construct(
        protected OrganismoObservacionRelayService $relay,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        if (! is_array($payload)) {
            return $this->error('Error de sintaxis, JSON mal formado');
        }

        $accion = $payload['accion'] ?? null;
        if ($accion === null) {
            return $this->error('no viene accion');
        }

        try {
            return match ((string) $accion) {
                'graba' => $this->graba($payload),
                default => $this->error('Accion no existe: '.$accion),
            };
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function graba(array $payload): JsonResponse
    {
        $resultado = $this->relay->recibir($payload);

        return response()->json([
            'resultado' => 'OK',
            'mensaje' => $resultado['created'] ? 'Organismo creado' : 'Organismo actualizado',
            'rut_organismo' => $resultado['rut_organismo'],
            'created' => $resultado['created'],
        ]);
    }

    private function decodeJson(Request $request): mixed
    {
        $payload = $request->json()->all();
        if ($payload !== []) {
            return $payload;
        }

        $raw = $request->getContent();
        if ($raw === '') {
            return [];
        }

        return json_decode($raw, true);
    }

    private function error(string $mensaje): JsonResponse
    {
        return response()->json([
            'resultado' => 'ERROR',
            'mensaje' => $mensaje,
        ], 400);
    }
}

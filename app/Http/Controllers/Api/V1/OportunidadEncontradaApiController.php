<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\OportunidadEncontradaRelayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class OportunidadEncontradaApiController extends Controller
{
    public function __construct(
        protected OportunidadEncontradaRelayService $relay,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        if (! is_array($payload)) {
            return $this->error('Error de sintaxis, JSON mal formado');
        }

        $accion = (string) ($payload['accion'] ?? '');
        if ($accion === '') {
            return $this->error('no viene accion');
        }

        if ($accion === 'tomada') {
            try {
                $resultado = $this->relay->recibirTomada(
                    (string) ($payload['codigo'] ?? ''),
                    isset($payload['usuario']) ? (string) $payload['usuario'] : null,
                    isset($payload['origen_sistema']) ? (string) $payload['origen_sistema'] : null,
                );

                return response()->json([
                    'resultado' => 'OK',
                    'mensaje' => ($resultado['created'] ?? false)
                        ? 'Oportunidad reservada'
                        : 'Oportunidad ya reservada por el mismo origen',
                    'codigo' => $resultado['codigo'],
                    'created' => $resultado['created'] ?? false,
                ]);
            } catch (RuntimeException $e) {
                return $this->conflicto($e->getMessage());
            }
        }

        if ($accion !== 'graba') {
            return $this->error('Accion no existe: '.$accion);
        }

        $items = $payload['items'] ?? null;
        if (! is_array($items) || $items === []) {
            return $this->error('no vienen items');
        }

        try {
            $resultado = $this->relay->recibir($items);

            return response()->json([
                'resultado' => 'OK',
                'mensaje' => 'Oportunidades recibidas',
                'recibidos' => $resultado['recibidos'],
            ]);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage());
        }
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

    private function conflicto(string $mensaje): JsonResponse
    {
        return response()->json([
            'resultado' => 'ERROR',
            'mensaje' => $mensaje,
        ], 409);
    }
}

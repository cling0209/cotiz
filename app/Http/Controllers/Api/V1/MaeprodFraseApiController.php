<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MaeprodFraseRelayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class MaeprodFraseApiController extends Controller
{
    public function __construct(
        protected MaeprodFraseRelayService $relay,
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

        $accion = (string) $accion;

        try {
            $resultado = $this->relay->recibir($accion, $payload);

            return response()->json([
                'resultado' => 'OK',
                'mensaje' => $resultado['mensaje'] ?? match ($accion) {
                    'graba' => ($resultado['created'] ?? false) ? 'Frase creada' : 'Frase ya existía',
                    'elimina' => ($resultado['deleted'] ?? false) ? 'Frase eliminada' : 'Frase no existía',
                    default => 'OK',
                },
                'prod_item' => $resultado['prod_item'] ?? null,
                'frase_norm' => $resultado['frase_norm'] ?? null,
                'created' => $resultado['created'] ?? null,
                'deleted' => $resultado['deleted'] ?? null,
                'skipped' => $resultado['skipped'] ?? false,
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
}

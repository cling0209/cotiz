<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\NotaRecepcionApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class NotaApiController extends Controller
{
    public function __construct(
        protected NotaRecepcionApiService $recepcionService,
    ) {}

    /**
     * Equivalente legacy apinota.php.
     */
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
            return match ($accion) {
                'graba_resumen' => $this->grabaResumen($payload),
                'graba_detalle' => $this->grabaDetalle($payload),
                default => $this->error('Accion no existe: '.$accion),
            };
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    private function grabaResumen(array $payload): JsonResponse
    {
        $nronota = $this->recepcionService->grabaResumen($payload);

        return response()->json([
            'resultado' => 'OK',
            'mensaje' => '',
            'nronota' => $nronota,
        ]);
    }

    private function grabaDetalle(array $payload): JsonResponse
    {
        $this->recepcionService->grabaDetalle($payload);
        $nronota = (int) ($payload['nronota'] ?? 0);

        return response()->json([
            'resultado' => 'OK',
            'mensaje' => '',
            'nronota' => $nronota,
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

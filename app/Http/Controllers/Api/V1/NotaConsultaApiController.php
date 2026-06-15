<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\NotaRecepcionApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class NotaConsultaApiController extends Controller
{
    public function __construct(
        protected NotaRecepcionApiService $recepcionService,
    ) {}

    /**
     * Equivalente legacy apiconsulta.php.
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

        if ($accion !== 'cotizacion') {
            return $this->error('Accion no existe: '.$accion);
        }

        try {
            $nronota = $this->recepcionService->consultarPorEncargado((string) ($payload['encargado'] ?? ''));
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage());
        }

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

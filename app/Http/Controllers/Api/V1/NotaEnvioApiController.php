<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\NotaEnvioRelayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class NotaEnvioApiController extends Controller
{
    public function __construct(
        protected NotaEnvioRelayService $relayService,
    ) {}

    /**
     * Equivalente legacy apinotaenvio.php.
     */
    public function store(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        if (! is_array($payload)) {
            return $this->error('Error de sintaxis, JSON mal formado');
        }

        try {
            $this->relayService->relayDesdeSolicitud($payload);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage());
        }

        return response()->json([
            'resultado' => 'OK',
            'mensaje' => '',
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

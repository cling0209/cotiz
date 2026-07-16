<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\OportunidadPalabraClaveRelayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class OportunidadPalabraClaveApiController extends Controller
{
    public function __construct(
        protected OportunidadPalabraClaveRelayService $relay,
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
            if ($accion === 'reordenar') {
                $frases = $payload['frases'] ?? null;
                if (! is_array($frases) || $frases === []) {
                    return $this->error('no vienen frases');
                }

                $resultado = $this->relay->recibir('reordenar', '', $frases);

                return response()->json([
                    'resultado' => 'OK',
                    'mensaje' => 'Orden de palabras clave actualizado',
                    'actualizados' => $resultado['actualizados'] ?? 0,
                    'frase' => $resultado['frase'] ?? null,
                ]);
            }

            $frase = trim((string) ($payload['frase'] ?? ''));
            if ($frase === '') {
                return $this->error('no viene frase');
            }

            $resultado = $this->relay->recibir($accion, $frase);

            return response()->json([
                'resultado' => 'OK',
                'mensaje' => match ($accion) {
                    'graba' => ($resultado['created'] ?? false) ? 'Palabra clave creada' : 'Palabra clave ya existía',
                    'elimina' => ($resultado['deleted'] ?? false) ? 'Palabra clave eliminada' : 'Palabra clave no existía',
                    default => 'OK',
                },
                'frase' => $resultado['frase'],
                'created' => $resultado['created'] ?? null,
                'deleted' => $resultado['deleted'] ?? null,
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

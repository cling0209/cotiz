<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\UserRecepcionApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class UserApiController extends Controller
{
    public function __construct(
        protected UserRecepcionApiService $recepcionService,
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
            return match ($accion) {
                'graba' => $this->graba($payload),
                default => $this->error('Accion no existe: '.$accion),
            };
        } catch (ValidationException $e) {
            $mensaje = collect($e->errors())->flatten()->first();

            return $this->error($mensaje ?: 'Datos inválidos');
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    private function graba(array $payload): JsonResponse
    {
        $resultado = $this->recepcionService->graba($payload);

        return response()->json([
            'resultado' => 'OK',
            'mensaje' => $resultado['created'] ? 'Usuario creado' : 'Usuario ya existía',
            'username' => $resultado['username'],
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

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AgileRecepcionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AgileController extends Controller
{
    public function __construct(
        protected AgileRecepcionService $agileService,
    ) {}

    /**
     * Equivalente legacy apiagile.php (acción graba).
     */
    public function store(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        if ($payload === []) {
            $raw = $request->getContent();
            if ($raw !== '') {
                $payload = json_decode($raw, true) ?? [];
            }
        }

        if (! is_array($payload)) {
            return $this->error('Error de sintaxis, JSON mal formado');
        }

        $accion = $payload['accion'] ?? null;
        if ($accion === null) {
            return $this->error('no viene accion');
        }

        if ($accion !== 'graba') {
            return $this->error('Accion no existe: '.$accion);
        }

        if (! isset($payload['usuario']) || trim((string) $payload['usuario']) === '') {
            return $this->error('no viene usuario');
        }

        if (! isset($payload['codigo_cotizacion']) || trim((string) $payload['codigo_cotizacion']) === '') {
            return $this->error('no viene codigo_cotizacion');
        }

        if (! isset($payload['productos']) || ! is_array($payload['productos'])) {
            return $this->error('no viene productos');
        }

        try {
            $nronota = $this->agileService->recibirDesdeApi([
                'usuario' => trim((string) $payload['usuario']),
                'codigo_cotizacion' => trim((string) $payload['codigo_cotizacion']),
                'rut_empresa' => trim((string) ($payload['rut_empresa'] ?? '')),
                'nombre_empresa' => trim((string) ($payload['nombre_empresa'] ?? '')),
                'productos' => $payload['productos'],
            ]);
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage());
        }

        return response()->json([
            'resultado' => 'OK',
            'mensaje' => 'Datos guardados correctamente',
            'nroenvio' => $nronota,
        ]);
    }

    private function error(string $mensaje): JsonResponse
    {
        return response()->json([
            'resultado' => 'ERROR',
            'mensaje' => $mensaje,
        ], 400);
    }
}

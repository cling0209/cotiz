<?php

namespace App\Services;

use App\Models\Nota;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NotaEnvioApiService
{
    public function __construct(
        protected NotaEnvioRelayService $relayService,
    ) {}

    /**
     * Envía cotización al destino configurado (apinotaenvio legacy o relay interno).
     */
    public function enviar(Nota $nota, ?string $usuarioEnvio = null): void
    {
        $urlEnvio = trim((string) config('cotiz.api_nota_envio.url', ''));

        if ($urlEnvio === '') {
            $this->relayService->relay($nota, $usuarioEnvio);

            return;
        }

        $payload = [
            'nronota' => $nota->nronota,
            'enviadoapi' => 1,
            'diashabiles' => 0,
            'notaorigen' => 0,
        ];

        $request = Http::timeout(60)->asJson();

        $user = config('cotiz.api_nota_envio.user') ?: config('cotiz.api_nota.user');
        $password = config('cotiz.api_nota_envio.password') ?? config('cotiz.api_nota.password');
        if ($user !== null && $user !== '' && $password !== null) {
            $request = $request->withBasicAuth((string) $user, (string) $password);
        }

        $response = $request->post($urlEnvio, $payload);

        if (! $response->successful()) {
            throw new RuntimeException($this->mensajeErrorHttp($response, $urlEnvio));
        }

        $data = $response->json();
        if (! is_array($data) || ($data['resultado'] ?? '') !== 'OK') {
            $mensaje = is_array($data) ? (string) ($data['mensaje'] ?? 'Error desconocido') : 'Respuesta inválida';

            throw new RuntimeException('No se realizó el envío: '.$mensaje);
        }
    }

    private function mensajeErrorHttp(Response $response, string $url): string
    {
        $data = $response->json();
        if (is_array($data)) {
            $mensaje = trim((string) ($data['mensaje'] ?? ''));
            if ($mensaje !== '') {
                return $mensaje;
            }
        }

        return match ($response->status()) {
            401 => 'Autorización rechazada (401). Verifique COTIZ_API_NOTA_USER y COTIZ_API_NOTA_PASSWORD.',
            404 => 'Ruta no encontrada (404). URL configurada: '.$url,
            default => 'Error HTTP al enviar la cotización a la API ('.$response->status().').',
        };
    }
}

<?php

namespace App\Services;

use App\Models\Nota;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NotaEnvioApiService
{
    public function enviar(Nota $nota): void
    {
        $url = trim((string) config('cotiz.api_nota_envio.url', ''));

        if ($url === '') {
            throw new RuntimeException('No está configurada la URL de envío API (COTIZ_API_NOTA_ENVIO_URL).');
        }

        $nota->load('detalle');

        $payload = array_merge($nota->toArray(), [
            'nronota' => $nota->nronota,
            'enviadoapi' => 1,
            'diashabiles' => 0,
            'notaorigen' => 0,
        ]);

        $request = Http::timeout(30)->asJson();

        $user = config('cotiz.api_nota_envio.user');
        $password = config('cotiz.api_nota_envio.password');
        if ($user !== null && $user !== '' && $password !== null) {
            $request = $request->withBasicAuth((string) $user, (string) $password);
        }

        $response = $request->post($url, $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Error HTTP al enviar la cotización a la API.');
        }

        $data = $response->json();
        if (! is_array($data) || ($data['resultado'] ?? '') !== 'OK') {
            $mensaje = is_array($data) ? (string) ($data['mensaje'] ?? 'Error desconocido') : 'Respuesta inválida';

            throw new RuntimeException('No se realizó el envío: '.$mensaje);
        }
    }
}

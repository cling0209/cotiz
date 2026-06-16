<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class UserRelayService
{
    /**
     * Envía el usuario recién creado a la instancia par (Romulo ↔ Reicol).
     *
     * @return string Mensaje de éxito para mostrar al admin (creado en el par).
     *
     * @throws RuntimeException Si falla la replicación remota.
     */
    public function replicarDesdeLocal(User $user, string $plainPassword): string
    {
        $destino = trim((string) config('cotiz.api_usuario.url', ''));
        if ($destino === '') {
            throw new RuntimeException(
                'COTIZ_API_USUARIO_URL no está configurada en este servidor. '
                .'En Reicol debe apuntar a https://cotiza.romulo.cl/api/v1/usuario y en Romulo a https://cotiza.reicol.cl/api/v1/usuario.'
            );
        }

        $userAuth = (string) config('cotiz.api_nota.user', '');
        $passwordAuth = (string) config('cotiz.api_nota.password', '');
        if ($userAuth === '' || $passwordAuth === '') {
            throw new RuntimeException(
                'Faltan COTIZ_API_NOTA_USER o COTIZ_API_NOTA_PASSWORD (Basic Auth compartida con la otra instancia).'
            );
        }

        $peer = $this->nombreInstanciaPar($destino);

        $payload = [
            'accion' => 'graba',
            'replicacion' => true,
            'origen_sistema' => (string) config('cotiz.sistema', config('app.name')),
            'username' => $user->username,
            'nombre' => $user->nombre,
            'apellidop' => $user->apellidop,
            'apellidom' => $user->apellidom,
            'correo' => $user->correo,
            'perfil' => (int) $user->perfil,
            'password' => $plainPassword,
        ];

        try {
            $response = Http::timeout(30)
                ->asJson()
                ->withBasicAuth($userAuth, $passwordAuth)
                ->post($destino, $payload);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'No se pudo conectar con '.$peer.' ('.$destino.'): '.$e->getMessage()
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException($this->mensajeErrorHttp($response, $destino, $peer));
        }

        $data = $response->json();
        if (! is_array($data) || ($data['resultado'] ?? '') !== 'OK') {
            $mensaje = is_array($data) ? trim((string) ($data['mensaje'] ?? '')) : '';

            throw new RuntimeException(
                $mensaje !== ''
                    ? $peer.': '.$mensaje
                    : 'Respuesta inválida de '.$peer.'.'
            );
        }

        $created = (bool) ($data['created'] ?? true);

        return $created
            ? 'También fue creado en '.$peer.'.'
            : 'En '.$peer.' el usuario ya existía (mismo username).';
    }

    private function mensajeErrorHttp(Response $response, string $destino, string $peer): string
    {
        $data = $response->json();
        if (is_array($data)) {
            $mensaje = trim((string) ($data['mensaje'] ?? ''));
            if ($mensaje !== '') {
                return $peer.': '.$mensaje;
            }
        }

        return match ($response->status()) {
            401 => $peer.': autorización rechazada (401). Verifique que COTIZ_API_NOTA_USER y COTIZ_API_NOTA_PASSWORD sean iguales en Romulo y Reicol.',
            404 => $peer.': ruta no encontrada (404). URL configurada: '.$destino,
            default => $peer.': error HTTP '.$response->status().' al llamar '.$destino.'.',
        };
    }

    private function nombreInstanciaPar(string $url): string
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));

        if (str_contains($host, 'reicol')) {
            return 'Reicol';
        }
        if (str_contains($host, 'romulo')) {
            return 'Romulo';
        }

        return $host !== '' ? $host : 'la otra instancia';
    }
}

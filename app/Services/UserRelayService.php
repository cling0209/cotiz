<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class UserRelayService
{
    /**
     * Envía el usuario recién creado a la instancia par (Romulo ↔ Reicol).
     */
    public function replicarDesdeLocal(User $user, string $plainPassword): void
    {
        $destino = trim((string) config('cotiz.api_usuario.url', ''));
        if ($destino === '') {
            return;
        }

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

        $request = Http::timeout(30)->asJson();
        $userAuth = (string) config('cotiz.api_nota.user', '');
        $passwordAuth = (string) config('cotiz.api_nota.password', '');

        if ($userAuth !== '') {
            $request = $request->withBasicAuth($userAuth, $passwordAuth);
        }

        $response = $request->post($destino, $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Error HTTP al replicar usuario en la instancia remota.');
        }

        $data = $response->json();
        if (! is_array($data) || ($data['resultado'] ?? '') !== 'OK') {
            $mensaje = is_array($data) ? (string) ($data['mensaje'] ?? 'Error desconocido') : 'Respuesta inválida';

            throw new RuntimeException('No se replicó el usuario: '.$mensaje);
        }
    }
}

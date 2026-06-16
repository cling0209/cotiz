<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class UserRecepcionApiService
{
    /**
     * Replica de usuario desde otra instancia (Romulo ↔ Reicol). Idempotente por username.
     *
     * @return array{created: bool, username: string}
     */
    public function graba(array $payload): array
    {
        $datos = validator($payload, [
            'username' => ['required', 'string', 'max:20', 'alpha_dash'],
            'nombre' => ['required', 'string', 'max:20'],
            'apellidop' => ['nullable', 'string', 'max:30'],
            'apellidom' => ['nullable', 'string', 'max:20'],
            'correo' => ['nullable', 'email', 'max:60'],
            'perfil' => ['required', 'integer', Rule::in([User::PERFIL_SUPERADMIN, User::PERFIL_EJECUTIVO])],
            'password' => ['required', 'string', 'max:20', Password::min(8)->letters()->numbers()],
        ])->validate();

        $username = trim((string) $datos['username']);
        if ($username === '') {
            throw new RuntimeException('username inválido');
        }

        if (User::query()->where('username', $username)->exists()) {
            return ['created' => false, 'username' => $username];
        }

        User::query()->create([
            'username' => $username,
            'nombre' => $datos['nombre'],
            'apellidop' => $datos['apellidop'] ?? null,
            'apellidom' => $datos['apellidom'] ?? null,
            'correo' => $datos['correo'] ?? null,
            'perfil' => (int) $datos['perfil'],
            'password' => $datos['password'],
        ]);

        return ['created' => true, 'username' => $username];
    }
}

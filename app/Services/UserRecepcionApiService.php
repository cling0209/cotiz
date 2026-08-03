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
            'puede_gestionar_frases' => ['sometimes', 'boolean'],
            'password' => ['required', 'string', 'max:20', Password::min(8)->letters()->numbers()],
        ])->validate();

        $username = trim((string) $datos['username']);
        if ($username === '') {
            throw new RuntimeException('username inválido');
        }

        if (User::query()->where('username', $username)->exists()) {
            return ['created' => false, 'username' => $username];
        }

        $perfil = (int) $datos['perfil'];
        $puedeGestionarFrases = $perfil === User::PERFIL_EJECUTIVO
            && filter_var($datos['puede_gestionar_frases'] ?? false, FILTER_VALIDATE_BOOLEAN);

        User::query()->create([
            'username' => $username,
            'nombre' => $datos['nombre'],
            'apellidop' => $datos['apellidop'] ?? null,
            'apellidom' => $datos['apellidom'] ?? null,
            'correo' => $datos['correo'] ?? null,
            'perfil' => $perfil,
            'puede_gestionar_frases' => $puedeGestionarFrases,
            'password' => $datos['password'],
        ]);

        return ['created' => true, 'username' => $username];
    }
}

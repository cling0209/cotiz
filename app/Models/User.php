<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['username', 'nombre', 'apellidop', 'apellidom', 'correo', 'perfil', 'empresa', 'ccosto', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const PERFIL_PEDIDOS = 1;

    public const PERFIL_ADMIN_CLIENTE = 2;

    public const PERFIL_SUPERADMIN = 3;

    public const PERFIL_EJECUTIVO = 4;

    public function fullName(): string
    {
        return trim(implode(' ', array_filter([
            $this->nombre,
            $this->apellidop,
            $this->apellidom,
        ])));
    }

    public function isSuperAdmin(): bool
    {
        return $this->perfil === self::PERFIL_SUPERADMIN;
    }

    public function isEjecutivo(): bool
    {
        return $this->perfil === self::PERFIL_EJECUTIVO;
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'perfil' => 'integer',
        ];
    }
}

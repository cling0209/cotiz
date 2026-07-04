<?php

namespace App\Models;

use App\Notifications\AdminResetPasswordNotification;
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

    public function canAccessPanel(): bool
    {
        return in_array($this->perfil, [self::PERFIL_SUPERADMIN, self::PERFIL_EJECUTIVO], true);
    }

    public function isAdmin(): bool
    {
        return $this->canAccessPanel();
    }

    public function canAccessCompraAgilAnalisis(): bool
    {
        return $this->username === 'admin'
            && config('cotiz.mercadopublico.analisis_admin_habilitado', false);
    }

    public function canAccessCompraAgilResultados(): bool
    {
        return in_array($this->perfil, [self::PERFIL_SUPERADMIN, self::PERFIL_ADMIN_CLIENTE], true)
            && config('cotiz.mercadopublico.resultados_admin_habilitado', true);
    }

    public function getEmailForPasswordReset(): string
    {
        return (string) ($this->correo ?? '');
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new AdminResetPasswordNotification($token));
    }

    public function getEmailAttribute(): ?string
    {
        return $this->correo;
    }

    public function setEmailAttribute(?string $value): void
    {
        $this->attributes['correo'] = $value;
    }

    public function getNameAttribute(): string
    {
        return $this->fullName() ?: $this->username;
    }

    public function perfilLabel(): string
    {
        return match ($this->perfil) {
            self::PERFIL_SUPERADMIN => 'Superadmin',
            self::PERFIL_EJECUTIVO => 'Ejecutivo',
            self::PERFIL_ADMIN_CLIENTE => 'Admin cliente',
            self::PERFIL_PEDIDOS => 'Pedidos',
            default => 'Perfil '.$this->perfil,
        };
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'perfil' => 'integer',
        ];
    }
}

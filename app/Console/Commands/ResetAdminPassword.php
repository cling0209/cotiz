<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
class ResetAdminPassword extends Command
{
    protected $signature = 'cotiz:reset-admin
                            {--username=admin : Usuario admin}
                            {--password=Admin123! : Nueva contraseña}
                            {--create : Crear el usuario si no existe}';

    protected $description = 'Restablece la contraseña del superadmin (útil si la tabla users quedó vacía)';

    public function handle(): int
    {
        $username = trim((string) $this->option('username'));
        $password = (string) $this->option('password');

        $user = User::query()->where('username', $username)->first();

        if (! $user && ! $this->option('create')) {
            $this->error("Usuario «{$username}» no existe. Usa --create para crearlo.");

            return self::FAILURE;
        }

        if (! $user) {
            $user = User::query()->create([
                'username' => $username,
                'nombre' => 'Admin',
                'apellidop' => 'Sistema',
                'correo' => $username.'@cotiz.local',
                'perfil' => User::PERFIL_SUPERADMIN,
                'password' => $password,
            ]);
            $this->info("Usuario «{$username}» creado.");

            return self::SUCCESS;
        }

        $user->update(['password' => $password]);
        $this->info("Contraseña de «{$username}» actualizada.");

        return self::SUCCESS;
    }
}

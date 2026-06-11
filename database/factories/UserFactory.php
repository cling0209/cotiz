<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'username' => Str::lower(fake()->unique()->userName()),
            'nombre' => fake()->firstName(),
            'apellidop' => fake()->lastName(),
            'apellidom' => '',
            'correo' => fake()->unique()->safeEmail(),
            'perfil' => User::PERFIL_EJECUTIVO,
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }
}

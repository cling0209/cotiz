<?php

namespace Database\Seeders;

use App\Models\Nota;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! DB::table('nronota_seq')->exists()) {
            DB::table('nronota_seq')->insert(['ultimo' => 0]);
        }

        User::query()->firstOrCreate(
            ['username' => 'admin'],
            [
                'nombre' => 'Admin',
                'apellidop' => 'Sistema',
                'correo' => 'admin@cotiz.local',
                'perfil' => User::PERFIL_SUPERADMIN,
                'password' => Hash::make('Admin123!'),
            ],
        );

        User::query()->firstOrCreate(
            ['username' => 'ejecutivo'],
            [
                'nombre' => 'Juan',
                'apellidop' => 'Pérez',
                'correo' => 'ejecutivo@cotiz.local',
                'perfil' => User::PERFIL_EJECUTIVO,
                'password' => Hash::make('Ejec123!'),
            ],
        );

        $this->call(DemoDataSeeder::class);

        if (Nota::query()->where('nronota', 1)->exists()) {
            return;
        }

        Nota::query()->create([
            'nronota' => 1,
            'descripcion' => 'Cotización demo',
            'fecha' => now()->toDateString(),
            'usuario' => 'ejecutivo',
            'empresa' => 'Empresa Demo SPA',
            'encargado' => 'COT-2026-001',
            'celular' => '+56912345678',
            'contacto' => 'María González',
            'contactocorreo' => 'maria@demo.cl',
            'nota_softland' => 10000,
            'diashabiles' => 2,
            'enviadoapi' => 0,
            'factor_precio_venta' => config('cotiz.factor_precio_venta'),
        ]);

        DB::table('nronota_seq')->update(['ultimo' => 1]);

        DB::table('notasdetalle')->insert([
            'nronota' => 1,
            'prod_item' => 'DEMO001',
            'prod_valor' => 4500,
            'cantidad' => 10,
            'fechahora' => now(),
            'orden' => 1,
            'prod_valor_costo' => 3600,
        ]);
    }
}

<?php

namespace Database\Seeders;

use App\Models\Gramaje;
use Illuminate\Database\Seeder;

class GramajeSeeder extends Seeder
{
    public function run(): void
    {
        if (Gramaje::query()->exists()) {
            return;
        }

        Gramaje::query()->insert([
            ['codigo' => 1, 'nombre' => 'unidad'],
            ['codigo' => 2, 'nombre' => 'caja'],
            ['codigo' => 3, 'nombre' => 'paquete'],
            ['codigo' => 4, 'nombre' => 'rollo'],
            ['codigo' => 5, 'nombre' => 'pack'],
            ['codigo' => 6, 'nombre' => 'juego'],
            ['codigo' => 7, 'nombre' => 'metro'],
            ['codigo' => 8, 'nombre' => 'kilo'],
            ['codigo' => 9, 'nombre' => 'resma'],
            ['codigo' => 10, 'nombre' => 'pliego'],
        ]);
    }
}

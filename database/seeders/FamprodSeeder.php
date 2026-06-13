<?php

namespace Database\Seeders;

use App\Models\Famprod;
use Illuminate\Database\Seeder;

class FamprodSeeder extends Seeder
{
    public function run(): void
    {
        if (Famprod::query()->exists()) {
            return;
        }

        Famprod::query()->insert([
            ['codigo' => '3M', 'nombre' => '3M'],
            ['codigo' => 'ABARR', 'nombre' => 'ABARR'],
            ['codigo' => 'ASEO', 'nombre' => 'ASEO'],
            ['codigo' => 'CAFET', 'nombre' => 'CAFET'],
            ['codigo' => 'CARTRID', 'nombre' => 'CARTRID'],
            ['codigo' => 'CINTA', 'nombre' => 'CINTA'],
            ['codigo' => 'COMPUT', 'nombre' => 'COMPUT'],
            ['codigo' => 'CUMPLEAÑO', 'nombre' => 'CUMPLEAÑO'],
            ['codigo' => 'DATA', 'nombre' => 'DATA'],
            ['codigo' => 'DRUM', 'nombre' => 'DRUM'],
            ['codigo' => 'ELECTR', 'nombre' => 'ELECTR'],
            ['codigo' => 'FERRET', 'nombre' => 'FERRET'],
            ['codigo' => 'FORMUL', 'nombre' => 'FORMUL'],
            ['codigo' => 'LBLANCA', 'nombre' => 'LBLANCA'],
            ['codigo' => 'LIBR', 'nombre' => 'LIBRERIA'],
            ['codigo' => 'MUEBLE', 'nombre' => 'MUEBLE'],
            ['codigo' => 'OTRO', 'nombre' => 'OTRO'],
            ['codigo' => 'PAPEL', 'nombre' => 'PAPEL'],
            ['codigo' => 'PILAS', 'nombre' => 'PILAS'],
            ['codigo' => 'SEGURIDAD', 'nombre' => 'SEGURIDAD'],
            ['codigo' => 'TINTA', 'nombre' => 'TINTA'],
            ['codigo' => 'TONER', 'nombre' => 'TONER'],
        ]);
    }
}

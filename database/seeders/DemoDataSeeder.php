<?php

namespace Database\Seeders;

use App\Models\Maeprod;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(GramajeSeeder::class);
        $this->call(FamprodSeeder::class);

        $productos = [
            [
                'prod_item' => 'DEMO001',
                'prod_nombre' => 'PRODUCTO DEMO PAPEL BOND A4',
                'prod_imagen' => 'DEMO001_medium.jpg',
                'prod_valor' => 4500,
                'prod_valor_costo' => 3600,
                'prod_familia' => 'PAPEL',
                'prod_gramaje' => 'resma',
            ],
            [
                'prod_item' => 'DEMO002',
                'prod_nombre' => 'PRODUCTO DEMO CARPETA OFICIO',
                'prod_imagen' => 'DEMO002_medium.jpg',
                'prod_valor' => 1200,
                'prod_valor_costo' => 900,
                'prod_familia' => 'PAPEL',
                'prod_gramaje' => 'unidad',
            ],
            [
                'prod_item' => 'DEMO003',
                'prod_nombre' => 'PRODUCTO DEMO LAPIZ GRAFITO',
                'prod_imagen' => '73027_medium.jpg',
                'prod_valor' => 350,
                'prod_valor_costo' => 250,
                'prod_familia' => 'LIBR',
                'prod_gramaje' => 'unidad',
            ],
        ];

        foreach ($productos as $datos) {
            Maeprod::query()->updateOrCreate(
                ['prod_item' => $datos['prod_item']],
                array_merge($datos, ['prod_valor_fecha' => now()])
            );
        }
    }
}

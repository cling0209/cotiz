<?php

namespace App\Console\Commands;

use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;

class SeedDemoDataCommand extends Command
{
    protected $signature = 'cotiz:seed-demo';

    protected $description = 'Carga familias de producto y productos demo (sin borrar usuarios ni cotizaciones)';

    public function handle(): int
    {
        $this->call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true]);

        $this->info('Familias y productos demo listos.');

        return self::SUCCESS;
    }
}

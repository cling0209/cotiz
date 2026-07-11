<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (config('cotiz.mercadopublico.resultados_schedule_habilitado', true)) {
    $horas = collect(explode(',', (string) config('cotiz.mercadopublico.resultados_schedule_hours', '10,19')))
        ->map(fn ($h) => (int) trim((string) $h))
        ->filter(fn ($h) => $h >= 0 && $h <= 23)
        ->unique()
        ->sort()
        ->values();

    if ($horas->isNotEmpty()) {
        Schedule::command('compra-agil:consultar-resultados')
            ->cron('0 '.$horas->implode(',').' * * *')
            ->timezone(config('app.timezone', 'America/Santiago'))
            ->withoutOverlapping(120);
    }
}

<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class HoraChile
{
    public static function timezone(): string
    {
        return (string) config('app.timezone', 'America/Santiago');
    }

    public static function format(mixed $fecha, string $formato = 'd/m/Y H:i:s'): string
    {
        if ($fecha === null || $fecha === '') {
            return '—';
        }

        try {
            $dt = $fecha instanceof CarbonInterface
                ? $fecha->copy()
                : Carbon::parse($fecha);
        } catch (\Throwable) {
            return '—';
        }

        return $dt->timezone(self::timezone())->format($formato);
    }
}

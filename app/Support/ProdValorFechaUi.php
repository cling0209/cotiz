<?php

namespace App\Support;

use Carbon\Carbon;

class ProdValorFechaUi
{
    public static function umbralMeses(): int
    {
        $meses = (int) config('cotiz.prod_valor_fecha_meses', 1);

        return $meses > 0 ? $meses : 1;
    }

    /**
     * @return array{0: string, 1: bool} [texto dd/mm/YYYY, es antigua]
     */
    public static function textoYAntigua(mixed $fecha): array
    {
        if ($fecha === null || $fecha === '') {
            return ['', false];
        }

        try {
            $dt = $fecha instanceof Carbon ? $fecha->copy() : Carbon::parse($fecha);
        } catch (\Throwable) {
            return ['', false];
        }

        if ($dt->year < 1970) {
            return ['', false];
        }

        $limite = now()->subMonths(self::umbralMeses());

        return [$dt->format('d/m/Y'), $dt->lt($limite)];
    }
}

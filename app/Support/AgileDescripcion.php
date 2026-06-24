<?php

namespace App\Support;

/**
 * Límites según migración 2026_06_12_000002_add_agile_reception_tables.
 */
final class AgileDescripcion
{
    public const MAEPROD_MAX = 255;

    public const DETALLE_MAX = 500;

    public static function normalizar(?string $valor): string
    {
        return str_replace("'", '´', trim((string) $valor));
    }

    public static function paraMaeprod(?string $valor): ?string
    {
        return self::truncar(self::normalizar($valor), self::MAEPROD_MAX);
    }

    public static function paraDetalle(?string $valor): ?string
    {
        return self::truncar(self::normalizar($valor), self::DETALLE_MAX);
    }

    private static function truncar(string $texto, int $max): ?string
    {
        if ($texto === '') {
            return null;
        }

        if (mb_strlen($texto) <= $max) {
            return $texto;
        }

        return mb_substr($texto, 0, $max);
    }
}

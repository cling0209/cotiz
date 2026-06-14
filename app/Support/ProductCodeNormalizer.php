<?php

namespace App\Support;

class ProductCodeNormalizer
{
    /**
     * Normaliza códigos de producto evitando notación científica (p. ej. 5,01E+13).
     */
    public static function normalize(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (floor($value) === $value) {
                return sprintf('%.0f', $value);
            }

            return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        $code = trim((string) $value);
        if ($code === '') {
            return '';
        }

        return self::expandScientificString($code) ?? $code;
    }

    private static function expandScientificString(string $code): ?string
    {
        $compact = str_replace(' ', '', $code);
        if (! preg_match('/^([\d]+(?:[,\.]\d+)?)[eE]([+\-]?\d+)$/', $compact, $matches)) {
            return null;
        }

        $mantissa = (float) str_replace(',', '.', $matches[1]);
        $exponent = (int) $matches[2];

        return sprintf('%.0f', $mantissa * (10 ** $exponent));
    }
}

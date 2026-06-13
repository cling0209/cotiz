<?php

namespace App\Support;

/**
 * Línea de importación de productos Softland (legacy sincodigosoftland_txt.php).
 */
class SoftlandProductoImportLine
{
    public static function build(string $codigo, string $nombre, int $precioNeto, int $precioConImpuesto): string
    {
        $q = static fn (string $v) => '"'.str_replace('"', '""', $v).'"';
        $sep = ';';

        $campos = [
            $q($codigo),
            $q($nombre),
            $q($nombre),
            $q(''),
            $q(''),
            $q('1'),
            $q(''),
            '',
            $q(''),
            '',
            $q(''),
            $q(''),
            '1',
            $q(''),
            $q('01'),
            $q(''),
            $q('01'),
            (string) $precioNeto,
            '',
            '',
            (string) $precioConImpuesto,
            '',
            '',
            $q('SI'),
            $q('SI'),
            $q('NO'),
            $q('NO'),
            $q('NO'),
            $q('NO'),
            '',
            $q('11-10-801'),
            $q('41-10-001'),
            $q('31-10-001'),
            $q('31-10-001'),
            '',
            '',
            '',
            '',
            $q('NO'),
            $q(''),
            '',
            $q('NO'),
            $q(''),
            $q('11-10-801'),
            $q(''),
            $q('NO'),
            $q('NO'),
            $q('SI'),
            $q('SI'),
            $q(''),
            $q('SI'),
            '',
            $q(''),
            $q(''),
            $q(''),
            $q(''),
            '',
            '',
            '',
            '',
            $q(''),
            '',
            '',
            '',
            '',
            $q(''),
            '',
            '',
            '',
            '',
            $q(''),
            $q(''),
            '',
            $q(''),
            $q('1'),
        ];

        return implode($sep, $campos);
    }
}

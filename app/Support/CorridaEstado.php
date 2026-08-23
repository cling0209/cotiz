<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Throwable;

class CorridaEstado
{
    /**
     * @param  list<mixed>|array<string, mixed>|null  $errores
     * @return array<string, mixed>|null
     */
    public static function ultimoError(mixed $errores): ?array
    {
        if (! is_array($errores) || $errores === []) {
            return null;
        }

        foreach (array_reverse(array_values($errores)) as $item) {
            if (! is_array($item)) {
                $txt = trim((string) $item);
                if ($txt === '') {
                    continue;
                }

                return ['mensaje' => $txt];
            }

            $mensaje = trim((string) ($item['mensaje'] ?? $item['error'] ?? $item['message'] ?? ''));
            if ($mensaje === '' && ! empty($item['encadenada'])) {
                continue;
            }
            if ($mensaje === '') {
                $mensaje = 'Error sin detalle';
            }

            return array_merge($item, ['mensaje' => $mensaje]);
        }

        return null;
    }

    public static function duracionSegundos(mixed $inicio, mixed $fin): ?int
    {
        if ($inicio === null || $inicio === '' || $fin === null || $fin === '') {
            return null;
        }

        try {
            $from = $inicio instanceof CarbonInterface ? $inicio : Carbon::parse($inicio);
            $to = $fin instanceof CarbonInterface ? $fin : Carbon::parse($fin);

            return max(0, (int) $from->diffInSeconds($to));
        } catch (Throwable) {
            return null;
        }
    }

    public static function formatearSegundos(?int $segs): ?string
    {
        if ($segs === null) {
            return null;
        }

        $h = intdiv($segs, 3600);
        $m = intdiv($segs % 3600, 60);
        $s = $segs % 60;
        if ($h > 0) {
            return sprintf('%dh %02dm %02ds', $h, $m, $s);
        }
        if ($m > 0) {
            return sprintf('%dm %02ds', $m, $s);
        }

        return sprintf('%ds', $s);
    }
}

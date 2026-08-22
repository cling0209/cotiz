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

        $ultimo = $errores[array_key_last($errores)];
        if (! is_array($ultimo)) {
            $txt = trim((string) $ultimo);

            return $txt === '' ? null : ['mensaje' => $txt];
        }

        $mensaje = trim((string) ($ultimo['mensaje'] ?? $ultimo['error'] ?? $ultimo['message'] ?? ''));
        if ($mensaje === '') {
            $mensaje = 'Error sin detalle';
        }

        return array_merge($ultimo, ['mensaje' => $mensaje]);
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

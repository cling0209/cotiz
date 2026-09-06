<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Parametro extends Model
{
    public const CLAVE_PAGO_COTIZACION_REALIZADA = 'pago_cotizacion_realizada';

    public $incrementing = false;

    protected $primaryKey = 'clave';

    protected $keyType = 'string';

    protected $table = 'parametros';

    protected $fillable = ['clave', 'nombre', 'valor', 'descripcion'];

    public static function getValue(string $clave, mixed $default = null): mixed
    {
        $all = Cache::remember('parametros', 300, fn () => self::query()->pluck('valor', 'clave')->all());

        if (! array_key_exists($clave, $all)) {
            return $default;
        }

        return $all[$clave];
    }

    public static function getInt(string $clave, int $default = 0): int
    {
        $raw = self::getValue($clave, null);
        if ($raw === null || trim((string) $raw) === '') {
            return $default;
        }

        $digits = preg_replace('/[^\d]/', '', (string) $raw) ?? '';

        return $digits === '' ? $default : (int) $digits;
    }

    public static function setValue(string $clave, mixed $valor): void
    {
        self::query()->where('clave', $clave)->update([
            'valor' => $valor === null ? null : (string) $valor,
            'updated_at' => now(),
        ]);
        Cache::forget('parametros');
    }

    public static function forgetCache(): void
    {
        Cache::forget('parametros');
    }
}

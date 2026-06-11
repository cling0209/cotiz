<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class ShippingSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $settings = collect(Cache::remember('shipping_settings', 300, fn () => self::query()->pluck('value', 'key')->all()));

        if (! $settings->has($key)) {
            return $default;
        }

        return $settings->get($key);
    }

    public static function getFloat(string $key, float $default = 0): float
    {
        return (float) self::getValue($key, $default);
    }

    public static function setValue(string $key, mixed $value): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
        Cache::forget('shipping_settings');
    }
}

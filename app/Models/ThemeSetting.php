<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class ThemeSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function getValue(string $key): ?string
    {
        $settings = self::cachedSettings();

        if (! $settings->has($key)) {
            return null;
        }

        $value = trim((string) $settings->get($key));

        return $value !== '' ? $value : null;
    }

    public static function setValue(string $key, ?string $value): void
    {
        $normalized = $value !== null ? trim($value) : '';

        if ($normalized === '') {
            self::query()->where('key', $key)->delete();
            self::forgetCache();

            return;
        }

        self::query()->updateOrCreate(['key' => $key], ['value' => $normalized]);
        self::forgetCache();
    }

    public static function clearKeys(array $keys): void
    {
        if ($keys === []) {
            return;
        }

        self::query()->whereIn('key', $keys)->delete();
        self::forgetCache();
    }

    /** @return \Illuminate\Support\Collection<string, string|null> */
    protected static function cachedSettings()
    {
        return collect(Cache::remember('theme_settings', 300, fn () => self::query()->pluck('value', 'key')->all()));
    }

    protected static function forgetCache(): void
    {
        Cache::forget('theme_settings');
    }
}

<?php

namespace App\Support;

use App\Models\ThemeSetting;

class ThemePalette
{
    public const KEY_PRIMARY = 'theme_primary';

    public const KEY_PRIMARY_HOVER = 'theme_primary_hover';

    public const KEY_ACCENT = 'theme_accent';

    public const DEFAULT_PRIMARY = '#2563eb';

    public const DEFAULT_PRIMARY_HOVER = '#1d4ed8';

    public const DEFAULT_ACCENT = '#0ea5e9';

    /** @var array<string, string> */
    public const DEFAULTS = [
        self::KEY_PRIMARY => self::DEFAULT_PRIMARY,
        self::KEY_PRIMARY_HOVER => self::DEFAULT_PRIMARY_HOVER,
        self::KEY_ACCENT => self::DEFAULT_ACCENT,
    ];

    public static function primary(): string
    {
        return self::resolve(self::KEY_PRIMARY);
    }

    public static function primaryHover(): string
    {
        return self::resolve(self::KEY_PRIMARY_HOVER);
    }

    public static function accent(): string
    {
        return self::resolve(self::KEY_ACCENT);
    }

    public static function primaryGradientStart(): string
    {
        return self::darken(self::primary(), 0.22);
    }

    public static function primaryGradientEnd(): string
    {
        return self::lighten(self::primary(), 0.12);
    }

    public static function faviconLetter(): string
    {
        $name = trim((string) config('app.name', 'Cotiz'));
        $letter = strtoupper(substr($name, 0, 1));

        return $letter !== '' ? $letter : 'C';
    }

    /** @return array<string, string|null> */
    public static function storedValues(): array
    {
        return [
            self::KEY_PRIMARY => ThemeSetting::getValue(self::KEY_PRIMARY),
            self::KEY_PRIMARY_HOVER => ThemeSetting::getValue(self::KEY_PRIMARY_HOVER),
            self::KEY_ACCENT => ThemeSetting::getValue(self::KEY_ACCENT),
        ];
    }

    /** @return array<string, string> */
    public static function resolved(): array
    {
        return [
            self::KEY_PRIMARY => self::primary(),
            self::KEY_PRIMARY_HOVER => self::primaryHover(),
            self::KEY_ACCENT => self::accent(),
        ];
    }

    public static function isValidHex(?string $value): bool
    {
        if ($value === null) {
            return true;
        }

        $value = trim($value);

        if ($value === '') {
            return true;
        }

        return (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', $value);
    }

    public static function normalizeHexInput(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : strtolower($value);
    }

    public static function primaryRgbCsv(): string
    {
        $rgb = self::hexToRgb(self::primary());

        return implode(', ', [$rgb['r'], $rgb['g'], $rgb['b']]);
    }

    public static function resetStored(): void
    {
        ThemeSetting::clearKeys(array_keys(self::DEFAULTS));
    }

    protected static function resolve(string $key): string
    {
        $stored = ThemeSetting::getValue($key);

        if ($stored !== null && self::isValidHex($stored)) {
            return strtolower($stored);
        }

        return self::DEFAULTS[$key];
    }

    protected static function darken(string $hex, float $amount): string
    {
        return self::adjustChannel($hex, static fn (int $channel) => (int) max(0, floor($channel * (1 - $amount))));
    }

    protected static function lighten(string $hex, float $amount): string
    {
        return self::adjustChannel($hex, static fn (int $channel) => (int) min(255, floor($channel + (255 - $channel) * $amount)));
    }

    /**
     * @param  callable(int): int  $transform
     */
    protected static function adjustChannel(string $hex, callable $transform): string
    {
        $rgb = self::hexToRgb($hex);

        return self::rgbToHex(
            $transform($rgb['r']),
            $transform($rgb['g']),
            $transform($rgb['b']),
        );
    }

    /** @return array{r: int, g: int, b: int} */
    protected static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            'r' => hexdec(substr($hex, 0, 2)),
            'g' => hexdec(substr($hex, 2, 2)),
            'b' => hexdec(substr($hex, 4, 2)),
        ];
    }

    protected static function rgbToHex(int $r, int $g, int $b): string
    {
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
}

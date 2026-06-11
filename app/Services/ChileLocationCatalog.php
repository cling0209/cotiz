<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ChileLocationCatalog
{
    /** @var list<array{codigo_region: string, region: string, comunas: list<array{codigo: string, nombre: string}>}>|null */
    protected static ?array $regionsCache = null;

    /** @var array<string, array{region: string, comuna: string, codigo_region: string}>|null */
    protected static ?array $cutIndexCache = null;

    /**
     * @return list<array{codigo_region: string, region: string, comunas: list<array{codigo: string, nombre: string}>}>
     */
    public static function regions(): array
    {
        if (self::$regionsCache !== null) {
            return self::$regionsCache;
        }

        $path = database_path('data/chile_regions.json');

        if (! File::exists($path)) {
            return self::$regionsCache = [];
        }

        $decoded = json_decode(File::get($path), true) ?? [];

        return self::$regionsCache = is_array($decoded) ? $decoded : [];
    }

    public static function normalizeCut(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', trim($raw)) ?? '';

        if ($digits === '') {
            return '';
        }

        return str_pad($digits, 5, '0', STR_PAD_LEFT);
    }

    public static function isMetropolitanaCut(string $codigo): bool
    {
        $normalized = self::normalizeCut($codigo);

        return $normalized !== '' && str_starts_with($normalized, '13');
    }

    /**
     * @return array{region: string, comuna: string, codigo: string, codigo_region: string}|null
     */
    public static function resolveByCut(string $codigo): ?array
    {
        $normalized = self::normalizeCut($codigo);

        if ($normalized === '') {
            return null;
        }

        self::buildCutIndex();

        $entry = self::$cutIndexCache[$normalized] ?? null;

        if ($entry === null) {
            return null;
        }

        return [
            'region' => $entry['region'],
            'comuna' => $entry['comuna'],
            'codigo' => $normalized,
            'codigo_region' => $entry['codigo_region'],
        ];
    }

    public static function lookupCutForComuna(string $region, string $comuna): ?string
    {
        foreach (self::regions() as $regionEntry) {
            if (($regionEntry['region'] ?? '') !== $region) {
                continue;
            }

            foreach ($regionEntry['comunas'] ?? [] as $comunaEntry) {
                $nombre = self::comunaNombre($comunaEntry);

                if ($nombre === $comuna) {
                    return self::normalizeCut((string) ($comunaEntry['codigo'] ?? ''));
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, list<string>>
     */
    public static function regionComunasExcludingRm(): array
    {
        $map = [];

        foreach (self::regions() as $entry) {
            $name = $entry['region'] ?? '';

            if ($name === '' || self::isMetropolitanaRegion($name)) {
                continue;
            }

            $map[$name] = collect($entry['comunas'] ?? [])
                ->map(fn (mixed $comuna): string => self::comunaNombre($comuna))
                ->filter()
                ->values()
                ->all();
        }

        return $map;
    }

    public static function isValidComuna(string $region, string $comuna): bool
    {
        $comunas = self::regionComunasExcludingRm()[$region] ?? [];

        return in_array($comuna, $comunas, true);
    }

    /**
     * @return list<array{codigo: string, nombre: string, region: string, codigo_region: string}>
     */
    public static function allComunasExcludingRm(): array
    {
        $rows = [];

        foreach (self::regions() as $entry) {
            $region = $entry['region'] ?? '';
            $codigoRegion = (string) ($entry['codigo_region'] ?? '');

            if ($region === '' || self::isMetropolitanaRegion($region)) {
                continue;
            }

            foreach ($entry['comunas'] ?? [] as $comunaEntry) {
                $nombre = self::comunaNombre($comunaEntry);
                $codigo = self::normalizeCut((string) ($comunaEntry['codigo'] ?? ''));

                if ($nombre === '' || $codigo === '') {
                    continue;
                }

                $rows[] = [
                    'codigo' => $codigo,
                    'nombre' => $nombre,
                    'region' => $region,
                    'codigo_region' => $codigoRegion,
                ];
            }
        }

        usort($rows, static function (array $a, array $b): int {
            $byCode = strcmp($a['codigo'], $b['codigo']);

            return $byCode !== 0 ? $byCode : strcmp($a['nombre'], $b['nombre']);
        });

        return $rows;
    }

    public static function comunaNombre(mixed $comuna): string
    {
        if (is_string($comuna)) {
            return $comuna;
        }

        if (is_array($comuna)) {
            return (string) ($comuna['nombre'] ?? '');
        }

        return '';
    }

    protected static function isMetropolitanaRegion(string $region): bool
    {
        return Str::contains(Str::lower($region), 'metropolitana');
    }

    protected static function buildCutIndex(): void
    {
        if (self::$cutIndexCache !== null) {
            return;
        }

        self::$cutIndexCache = [];

        foreach (self::regions() as $entry) {
            $region = $entry['region'] ?? '';
            $codigoRegion = (string) ($entry['codigo_region'] ?? '');

            foreach ($entry['comunas'] ?? [] as $comunaEntry) {
                $codigo = self::normalizeCut((string) ($comunaEntry['codigo'] ?? ''));
                $nombre = self::comunaNombre($comunaEntry);

                if ($codigo === '' || $nombre === '' || $region === '') {
                    continue;
                }

                self::$cutIndexCache[$codigo] = [
                    'region' => $region,
                    'comuna' => $nombre,
                    'codigo_region' => $codigoRegion,
                ];
            }
        }
    }
}

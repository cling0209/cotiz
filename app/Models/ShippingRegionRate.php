<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ShippingRegionRate extends Model
{
    protected $fillable = [
        'region',
        'flat_rate',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'flat_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public static function syncFromChileRegions(float $defaultFlatRate = 3000): void
    {
        foreach (self::chileRegionsExcludingRm() as $regionName) {
            self::query()->firstOrCreate(
                ['region' => $regionName],
                ['flat_rate' => $defaultFlatRate, 'is_active' => true]
            );
        }
    }

    /**
     * @return list<string>
     */
    public static function chileRegionsExcludingRm(): array
    {
        $path = database_path('data/chile_regions.json');

        if (! File::exists($path)) {
            return [];
        }

        $regions = json_decode(File::get($path), true) ?? [];

        return collect($regions)
            ->pluck('region')
            ->filter(fn (string $name) => ! Str::contains(Str::lower($name), 'metropolitana'))
            ->values()
            ->all();
    }

    public static function flatRateForRegion(string $region): ?float
    {
        $rate = self::query()
            ->active()
            ->where('region', $region)
            ->first();

        if (! $rate) {
            return null;
        }

        return (float) $rate->flat_rate;
    }
}

<?php

namespace App\Models;

use App\Services\ChileLocationCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingComunaWeightRate extends Model
{
    protected $fillable = [
        'region',
        'comuna',
        'label',
        'min_weight_kg',
        'max_weight_kg',
        'price',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'min_weight_kg' => 'decimal:3',
            'max_weight_kg' => 'decimal:3',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'shipping_comuna_weight_rate_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function matchesWeight(float $weightKg): bool
    {
        if ($weightKg < (float) $this->min_weight_kg) {
            return false;
        }

        if ($this->max_weight_kg !== null && $weightKg >= (float) $this->max_weight_kg) {
            return false;
        }

        return true;
    }

    public static function findForComunaAndWeight(string $region, string $comuna, float $totalWeightKg): ?self
    {
        return self::query()
            ->where('region', $region)
            ->where('comuna', $comuna)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('min_weight_kg')
            ->get()
            ->first(fn (self $rate) => $rate->matchesWeight($totalWeightKg));
    }

    public static function syncAllComunasFromChileData(): void
    {
        self::migrateLegacyGlobalRatesIfNeeded();

        $existingPairs = self::query()
            ->select('region', 'comuna')
            ->distinct()
            ->get()
            ->mapWithKeys(fn (self $row) => [$row->region."\0".$row->comuna => true])
            ->all();

        foreach (self::chileRegionComunasExcludingRm() as $region => $comunas) {
            foreach ($comunas as $comuna) {
                if (isset($existingPairs[$region."\0".$comuna])) {
                    continue;
                }

                self::seedDefaultsForComuna($region, $comuna);
                $existingPairs[$region."\0".$comuna] = true;
            }
        }
    }

    protected static function migrateLegacyGlobalRatesIfNeeded(): void
    {
        if (self::query()->exists() || ! class_exists(ShippingWeightRate::class)) {
            return;
        }

        $legacyRates = ShippingWeightRate::query()->orderBy('sort_order')->get();

        if ($legacyRates->isEmpty()) {
            return;
        }

        foreach (self::chileRegionComunasExcludingRm() as $region => $comunas) {
            foreach ($comunas as $comuna) {
                foreach ($legacyRates as $legacy) {
                    self::create([
                        'region' => $region,
                        'comuna' => $comuna,
                        'label' => $legacy->label,
                        'min_weight_kg' => $legacy->min_weight_kg,
                        'max_weight_kg' => $legacy->max_weight_kg,
                        'price' => $legacy->price,
                        'is_active' => $legacy->is_active,
                        'sort_order' => $legacy->sort_order,
                    ]);
                }
            }
        }
    }

    public static function seedDefaultsForComunaIfEmpty(string $region, string $comuna): void
    {
        if (self::query()->where('region', $region)->where('comuna', $comuna)->exists()) {
            return;
        }

        self::seedDefaultsForComuna($region, $comuna);
    }

    protected static function seedDefaultsForComuna(string $region, string $comuna): void
    {
        foreach (self::defaultBands() as $band) {
            self::create([
                'region' => $region,
                'comuna' => $comuna,
                'label' => $band['label'],
                'min_weight_kg' => $band['min'],
                'max_weight_kg' => $band['max'],
                'price' => $band['price'],
                'is_active' => true,
                'sort_order' => $band['sort'],
            ]);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public static function chileRegionComunasExcludingRm(): array
    {
        return ChileLocationCatalog::regionComunasExcludingRm();
    }

    public static function isValidComuna(string $region, string $comuna): bool
    {
        return ChileLocationCatalog::isValidComuna($region, $comuna);
    }

    public static function findByComunaAndWeightRange(
        string $region,
        string $comuna,
        float $minWeightKg,
        ?float $maxWeightKg,
    ): ?self {
        $query = self::query()
            ->where('region', $region)
            ->where('comuna', $comuna)
            ->where('min_weight_kg', $minWeightKg);

        if ($maxWeightKg === null) {
            $query->whereNull('max_weight_kg');
        } else {
            $query->where('max_weight_kg', $maxWeightKg);
        }

        return $query->first();
    }

    public static function formatLabelFromWeight(float $minKg, ?float $maxKg): string
    {
        $min = self::formatWeightForLabel($minKg);

        if ($maxKg === null) {
            return $minKg <= 0 ? 'Sin límite de peso' : 'Más de '.$min.' kg';
        }

        $max = self::formatWeightForLabel($maxKg);

        if ($minKg <= 0) {
            return 'Hasta '.$max.' kg';
        }

        return $min.' a '.$max.' kg';
    }

    protected static function formatWeightForLabel(float $kg): string
    {
        return rtrim(rtrim(number_format($kg, 3, '.', ''), '0'), '.');
    }

    /**
     * @return list<array{label: string, min: float, max: float|null, price: int, sort: int}>
     */
    public static function defaultBands(): array
    {
        return [
            ['label' => 'Hasta 1 kg', 'min' => 0, 'max' => 1, 'price' => 4990, 'sort' => 1],
            ['label' => '1 a 3 kg', 'min' => 1, 'max' => 3, 'price' => 6990, 'sort' => 2],
            ['label' => '3 a 5 kg', 'min' => 3, 'max' => 5, 'price' => 8990, 'sort' => 3],
            ['label' => 'Más de 5 kg', 'min' => 5, 'max' => null, 'price' => 11990, 'sort' => 4],
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingWeightRate extends Model
{
    protected $fillable = [
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
        return $this->hasMany(Order::class, 'shipping_weight_rate_id');
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

    public static function seedDefaultsIfEmpty(): void
    {
        if (self::query()->exists()) {
            return;
        }

        foreach (self::defaultBands() as $band) {
            self::create([
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

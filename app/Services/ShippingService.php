<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ShippingComunaWeightRate;
use App\Models\ShippingRegionRate;
use App\Models\ShippingSetting;
use Illuminate\Support\Str;

class ShippingService
{
    public const ZONE_RM = 'rm';

    public const ZONE_REGIONS = 'regions';

    public const RATE_FLAT_RM = 'flat_rm';

    public const RATE_REGION_FLAT_PLUS_WEIGHT = 'region_flat_plus_weight';

    public function quote(Cart $cart, string $region, ?string $comuna = null): array
    {
        $cart->loadMissing('items.product');

        if ($cart->items->isEmpty()) {
            throw new \InvalidArgumentException('El carrito está vacío.');
        }

        $weightBreakdown = $this->buildWeightBreakdown($cart);
        $totalWeight = $weightBreakdown['total_weight_kg'];

        if ($this->isMetropolitanRegion($region)) {
            $amount = ShippingSetting::getFloat('rm_flat_rate', 3990);

            return [
                'amount' => round($amount, 2),
                'zone' => self::ZONE_RM,
                'total_weight_kg' => $totalWeight,
                'rate_type' => self::RATE_FLAT_RM,
                'rate_label' => 'Tarifa fija Región Metropolitana',
                'comuna_weight_rate_id' => null,
                'metadata' => [
                    'region' => $region,
                    'comuna' => $comuna,
                    'rm_flat_rate' => $amount,
                    'items' => $weightBreakdown['items'],
                ],
            ];
        }

        $comuna = trim((string) $comuna);

        if ($comuna === '') {
            throw new \InvalidArgumentException('Selecciona una comuna para calcular el envío.');
        }

        if (! ShippingComunaWeightRate::isValidComuna($region, $comuna)) {
            throw new \InvalidArgumentException('La comuna seleccionada no es válida para la región.');
        }

        $regionFlatRate = ShippingRegionRate::flatRateForRegion($region);

        if ($regionFlatRate === null) {
            throw new \InvalidArgumentException(
                'No hay tarifa fija configurada para la región seleccionada.'
            );
        }

        $rate = ShippingComunaWeightRate::findForComunaAndWeight($region, $comuna, $totalWeight);
        $usedFallback = $rate === null;

        if ($usedFallback) {
            $weightTramoAmount = ShippingSetting::getFloat('fallback_additional_clp', 500);
            $rateLabel = 'Fija región + adicional fijo ('.$comuna.')';
            $comunaWeightRateId = null;
            $weightRateMeta = null;
        } else {
            $weightTramoAmount = (float) $rate->price;
            $rateLabel = 'Fija región + '.$rate->label.' ('.$comuna.')';
            $comunaWeightRateId = $rate->id;
            $weightRateMeta = [
                'id' => $rate->id,
                'label' => $rate->label,
                'min_weight_kg' => $rate->min_weight_kg,
                'max_weight_kg' => $rate->max_weight_kg,
                'price' => $rate->price,
            ];
        }

        $amount = round($regionFlatRate + $weightTramoAmount, 2);

        return [
            'amount' => $amount,
            'zone' => self::ZONE_REGIONS,
            'total_weight_kg' => $totalWeight,
            'rate_type' => self::RATE_REGION_FLAT_PLUS_WEIGHT,
            'rate_label' => $rateLabel,
            'comuna_weight_rate_id' => $comunaWeightRateId,
            'metadata' => [
                'region' => $region,
                'comuna' => $comuna,
                'region_flat_rate' => $regionFlatRate,
                'weight_tramo_amount' => $weightTramoAmount,
                'used_fallback_additional' => $usedFallback,
                'weight_rate' => $weightRateMeta,
                'items' => $weightBreakdown['items'],
            ],
        ];
    }

    public function isMetropolitanRegion(string $region): bool
    {
        $normalized = Str::lower(trim($region));

        return Str::contains($normalized, 'metropolitana');
    }

    protected function buildWeightBreakdown(Cart $cart): array
    {
        $defaultWeight = ShippingSetting::getFloat('default_product_weight_kg', 1.0);
        $items = [];
        $total = 0.0;

        foreach ($cart->items as $item) {
            /** @var CartItem $item */
            $product = $item->product;
            $unitWeight = $this->productWeightKg($product, $defaultWeight);
            $lineWeight = round($unitWeight * $item->quantity, 3);

            $items[] = [
                'product_id' => $product?->id,
                'sku' => $product?->sku,
                'name' => $product?->name,
                'quantity' => $item->quantity,
                'unit_weight_kg' => $unitWeight,
                'line_weight_kg' => $lineWeight,
                'used_default_weight' => $product?->weight_kg === null,
            ];

            $total += $lineWeight;
        }

        return [
            'total_weight_kg' => round($total, 3),
            'items' => $items,
        ];
    }

    protected function productWeightKg(?Product $product, float $default): float
    {
        if (! $product || $product->weight_kg === null) {
            return $default;
        }

        return max(0, (float) $product->weight_kg);
    }
}

<?php

namespace Database\Seeders;

use App\Models\ShippingComunaWeightRate;
use App\Models\ShippingRegionRate;
use App\Models\ShippingSetting;
use Illuminate\Database\Seeder;

class ShippingSeeder extends Seeder
{
    public function run(): void
    {
        ShippingSetting::setValue('rm_flat_rate', 3990);
        ShippingSetting::setValue('default_product_weight_kg', 1.0);
        ShippingSetting::setValue('fallback_additional_clp', 500);

        ShippingRegionRate::syncFromChileRegions(3000);
        ShippingComunaWeightRate::syncAllComunasFromChileData();
    }
}

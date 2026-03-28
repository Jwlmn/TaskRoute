<?php

namespace Database\Seeders;

use App\Models\CargoCategory;
use App\Models\FreightRateTemplate;
use Illuminate\Database\Seeder;

class FreightRateTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $gasoline = CargoCategory::query()->where('code', 'gasoline')->first();
        $retail = CargoCategory::query()->where('code', 'retail_goods')->first();

        FreightRateTemplate::query()->updateOrCreate(
            ['name' => '油品客户默认（按重量）'],
            [
                'client_name' => '华东油运客户',
                'cargo_category_id' => $gasoline?->id,
                'freight_calc_scheme' => 'by_weight',
                'freight_unit_price' => 8.80,
                'loss_allowance_kg' => 200,
                'loss_deduct_unit_price' => 1.50,
                'priority' => 300,
                'is_active' => true,
                'remark' => '油品客户通用结算模板',
            ]
        );

        FreightRateTemplate::query()->updateOrCreate(
            ['name' => '商超客户默认（按趟）'],
            [
                'client_name' => '华东商超客户',
                'cargo_category_id' => $retail?->id,
                'freight_calc_scheme' => 'by_trip',
                'freight_unit_price' => 420,
                'freight_trip_count' => 1,
                'loss_allowance_kg' => 0,
                'loss_deduct_unit_price' => 0,
                'priority' => 260,
                'is_active' => true,
                'remark' => '门店配送场景',
            ]
        );
    }
}

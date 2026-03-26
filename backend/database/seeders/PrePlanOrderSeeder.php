<?php

namespace Database\Seeders;

use App\Models\CargoCategory;
use App\Models\PrePlanOrder;
use Illuminate\Database\Seeder;

class PrePlanOrderSeeder extends Seeder
{
    public function run(): void
    {
        $gasoline = CargoCategory::query()->where('code', 'gasoline')->firstOrFail();
        $diesel = CargoCategory::query()->where('code', 'diesel')->firstOrFail();
        $seafood = CargoCategory::query()->where('code', 'seafood')->firstOrFail();
        $vegetable = CargoCategory::query()->where('code', 'vegetable')->firstOrFail();

        $mockOrders = [
            ['PO-INIT-0001', $gasoline->id, '中石化示例客户', '上海油库A', '上海加油站B', 5000, 7, 1, 3],
            ['PO-INIT-0002', $seafood->id, '商超配送中心', '上海冷链仓C', '上海商超门店D', 2000, 5, 1, 2],
            ['PO-INIT-0003', $gasoline->id, '中石化示例客户', '上海油库A', '上海加油站E', 4500, 6, 2, 4],
            ['PO-INIT-0004', $diesel->id, '中石化示例客户', '上海油库F', '上海工地G', 3500, 5, 2, 5],
            ['PO-INIT-0005', $seafood->id, '商超配送中心', '上海冷链仓C', '上海商超门店H', 1800, 4, 3, 5],
            ['PO-INIT-0006', $vegetable->id, '商超配送中心', '上海分拣仓I', '上海商超门店J', 2200, 6, 3, 6],
        ];

        foreach ($mockOrders as [$no, $cargoId, $client, $pickup, $dropoff, $weight, $volume, $start, $end]) {
            PrePlanOrder::query()->updateOrCreate(
                ['order_no' => $no],
                [
                    'cargo_category_id' => $cargoId,
                    'client_name' => $client,
                    'pickup_address' => $pickup,
                    'dropoff_address' => $dropoff,
                    'cargo_weight_kg' => $weight,
                    'cargo_volume_m3' => $volume,
                    'expected_pickup_at' => now()->addHours($start),
                    'expected_delivery_at' => now()->addHours($end),
                    'status' => 'pending',
                ]
            );
        }
    }
}


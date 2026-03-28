<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\LogisticsSite;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;

class VehicleResourceSeeder extends Seeder
{
    public function run(): void
    {
        $driverAId = User::query()->where('account', 'driver')->value('id');
        $driverBId = User::query()->where('account', 'driver2')->value('id');
        $driverCId = User::query()->where('account', 'driver3')->value('id');
        $siteAId = LogisticsSite::query()->where('name', '上海油库A')->value('id');
        $siteCId = LogisticsSite::query()->where('name', '上海冷链仓C')->value('id');
        $siteFId = LogisticsSite::query()->where('name', '上海油库F')->value('id');

        Vehicle::query()->updateOrCreate(
            ['plate_number' => '沪A12345'],
            [
                'name' => '油品罐车1号',
                'vehicle_type' => 'tank',
                'site_id' => $siteAId,
                'driver_id' => $driverAId,
                'max_weight_kg' => 18000,
                'max_volume_m3' => 30,
                'status' => 'idle',
                'meta' => [
                    'compartment_enabled' => true,
                    'compartments' => [
                        ['no' => 1, 'capacity_m3' => 15, 'allowed_cargo_category_ids' => [1]],
                        ['no' => 2, 'capacity_m3' => 15, 'allowed_cargo_category_ids' => [2]],
                    ],
                ],
            ]
        );

        Vehicle::query()->updateOrCreate(
            ['plate_number' => '沪B88990'],
            [
                'name' => '冷链车1号',
                'vehicle_type' => 'coldchain',
                'site_id' => $siteCId,
                'driver_id' => $driverBId,
                'max_weight_kg' => 8000,
                'max_volume_m3' => 20,
                'status' => 'idle',
                'meta' => [
                    'compartment_enabled' => false,
                    'compartments' => [],
                ],
            ]
        );

        Vehicle::query()->updateOrCreate(
            ['plate_number' => '沪A77778'],
            [
                'name' => '油品罐车2号',
                'vehicle_type' => 'tank',
                'site_id' => $siteFId,
                'driver_id' => $driverCId,
                'max_weight_kg' => 15000,
                'max_volume_m3' => 25,
                'status' => 'idle',
                'meta' => [
                    'compartment_enabled' => true,
                    'compartments' => [
                        ['no' => 1, 'capacity_m3' => 12, 'allowed_cargo_category_ids' => [1]],
                        ['no' => 2, 'capacity_m3' => 13, 'allowed_cargo_category_ids' => [1]],
                    ],
                ],
            ]
        );
    }
}

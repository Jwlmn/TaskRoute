<?php

namespace Database\Seeders;

use App\Models\Vehicle;
use Illuminate\Database\Seeder;

class VehicleResourceSeeder extends Seeder
{
    public function run(): void
    {
        Vehicle::query()->updateOrCreate(
            ['plate_number' => '沪A12345'],
            [
                'name' => '油品罐车1号',
                'vehicle_type' => 'tank',
                'max_weight_kg' => 18000,
                'max_volume_m3' => 30,
                'status' => 'idle',
            ]
        );

        Vehicle::query()->updateOrCreate(
            ['plate_number' => '沪B88990'],
            [
                'name' => '冷链车1号',
                'vehicle_type' => 'coldchain',
                'max_weight_kg' => 8000,
                'max_volume_m3' => 20,
                'status' => 'idle',
            ]
        );

        Vehicle::query()->updateOrCreate(
            ['plate_number' => '沪A77778'],
            [
                'name' => '油品罐车2号',
                'vehicle_type' => 'tank',
                'max_weight_kg' => 15000,
                'max_volume_m3' => 25,
                'status' => 'idle',
            ]
        );
    }
}


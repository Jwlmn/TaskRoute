<?php

namespace Database\Seeders;

use App\Models\CargoCategory;
use App\Models\PrePlanOrder;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@taskroute.local'],
            [
                'name' => '系统管理员',
                'phone' => '13800000001',
                'role' => 'admin',
                'status' => 'active',
                'password' => Hash::make('TaskRoute@123'),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'dispatcher@taskroute.local'],
            [
                'name' => '调度员A',
                'phone' => '13800000002',
                'role' => 'dispatcher',
                'status' => 'active',
                'password' => Hash::make('TaskRoute@123'),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'driver@taskroute.local'],
            [
                'name' => '司机A',
                'phone' => '13800000003',
                'role' => 'driver',
                'status' => 'active',
                'password' => Hash::make('TaskRoute@123'),
            ]
        );

        $gasoline = CargoCategory::query()->updateOrCreate(
            ['code' => 'gasoline'],
            ['name' => '汽油', 'temperature_zone' => 'ambient', 'description' => '油品运输']
        );
        $diesel = CargoCategory::query()->updateOrCreate(
            ['code' => 'diesel'],
            ['name' => '柴油', 'temperature_zone' => 'ambient', 'description' => '油品运输']
        );
        $seafood = CargoCategory::query()->updateOrCreate(
            ['code' => 'seafood'],
            ['name' => '海鲜', 'temperature_zone' => 'cold', 'description' => '生鲜冷链']
        );
        $vegetable = CargoCategory::query()->updateOrCreate(
            ['code' => 'vegetable'],
            ['name' => '蔬菜', 'temperature_zone' => 'cold', 'description' => '生鲜冷链']
        );

        DB::table('cargo_incompatibilities')->updateOrInsert(
            ['cargo_category_id' => $gasoline->id, 'incompatible_with_id' => $diesel->id],
            ['reason' => '汽油与柴油不可混装', 'updated_at' => now(), 'created_at' => now()]
        );
        DB::table('cargo_incompatibilities')->updateOrInsert(
            ['cargo_category_id' => $seafood->id, 'incompatible_with_id' => $vegetable->id],
            ['reason' => '海鲜与蔬菜不可混装', 'updated_at' => now(), 'created_at' => now()]
        );

        $oilVehicle = Vehicle::query()->updateOrCreate(
            ['plate_number' => '沪A12345'],
            [
                'name' => '油品罐车1号',
                'vehicle_type' => 'tank',
                'max_weight_kg' => 18000,
                'max_volume_m3' => 30,
                'status' => 'idle',
            ]
        );
        $coldVehicle = Vehicle::query()->updateOrCreate(
            ['plate_number' => '沪B88990'],
            [
                'name' => '冷链车1号',
                'vehicle_type' => 'coldchain',
                'max_weight_kg' => 8000,
                'max_volume_m3' => 20,
                'status' => 'idle',
            ]
        );

        DB::table('vehicle_cargo_rules')->updateOrInsert(
            ['vehicle_id' => $oilVehicle->id, 'cargo_category_id' => $gasoline->id],
            ['rule_type' => 'allow', 'reason' => '油品专车', 'updated_at' => now(), 'created_at' => now()]
        );
        DB::table('vehicle_cargo_rules')->updateOrInsert(
            ['vehicle_id' => $oilVehicle->id, 'cargo_category_id' => $diesel->id],
            ['rule_type' => 'deny', 'reason' => '当前车辆仅运输汽油', 'updated_at' => now(), 'created_at' => now()]
        );
        DB::table('vehicle_cargo_rules')->updateOrInsert(
            ['vehicle_id' => $coldVehicle->id, 'cargo_category_id' => $seafood->id],
            ['rule_type' => 'allow', 'reason' => '冷链生鲜车', 'updated_at' => now(), 'created_at' => now()]
        );
        DB::table('vehicle_cargo_rules')->updateOrInsert(
            ['vehicle_id' => $coldVehicle->id, 'cargo_category_id' => $vegetable->id],
            ['rule_type' => 'deny', 'reason' => '当前车辆只拉海鲜', 'updated_at' => now(), 'created_at' => now()]
        );

        PrePlanOrder::query()->updateOrCreate(
            ['order_no' => 'PO-INIT-0001'],
            [
                'cargo_category_id' => $gasoline->id,
                'client_name' => '中石化示例客户',
                'pickup_address' => '上海油库A',
                'dropoff_address' => '上海加油站B',
                'cargo_weight_kg' => 5000,
                'cargo_volume_m3' => 7,
                'expected_pickup_at' => now()->addHour(),
                'expected_delivery_at' => now()->addHours(3),
                'status' => 'pending',
            ]
        );

        PrePlanOrder::query()->updateOrCreate(
            ['order_no' => 'PO-INIT-0002'],
            [
                'cargo_category_id' => $seafood->id,
                'client_name' => '商超配送中心',
                'pickup_address' => '上海冷链仓C',
                'dropoff_address' => '上海商超门店D',
                'cargo_weight_kg' => 2000,
                'cargo_volume_m3' => 5,
                'expected_pickup_at' => now()->addHour(),
                'expected_delivery_at' => now()->addHours(2),
                'status' => 'pending',
            ]
        );
    }
}

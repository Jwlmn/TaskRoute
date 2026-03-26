<?php

namespace Database\Seeders;

use App\Models\CargoCategory;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CargoRuleSeeder extends Seeder
{
    public function run(): void
    {
        $gasoline = CargoCategory::query()->where('code', 'gasoline')->firstOrFail();
        $diesel = CargoCategory::query()->where('code', 'diesel')->firstOrFail();
        $seafood = CargoCategory::query()->where('code', 'seafood')->firstOrFail();
        $vegetable = CargoCategory::query()->where('code', 'vegetable')->firstOrFail();

        DB::table('cargo_incompatibilities')->updateOrInsert(
            ['cargo_category_id' => $gasoline->id, 'incompatible_with_id' => $diesel->id],
            ['reason' => '汽油与柴油不可混装', 'updated_at' => now(), 'created_at' => now()]
        );
        DB::table('cargo_incompatibilities')->updateOrInsert(
            ['cargo_category_id' => $seafood->id, 'incompatible_with_id' => $vegetable->id],
            ['reason' => '海鲜与蔬菜不可混装', 'updated_at' => now(), 'created_at' => now()]
        );

        $oilVehicle = Vehicle::query()->where('plate_number', '沪A12345')->firstOrFail();
        $coldVehicle = Vehicle::query()->where('plate_number', '沪B88990')->firstOrFail();
        $oilVehicle2 = Vehicle::query()->where('plate_number', '沪A77778')->firstOrFail();

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
        DB::table('vehicle_cargo_rules')->updateOrInsert(
            ['vehicle_id' => $oilVehicle2->id, 'cargo_category_id' => $diesel->id],
            ['rule_type' => 'allow', 'reason' => '柴油专车', 'updated_at' => now(), 'created_at' => now()]
        );
    }
}


<?php

namespace Database\Seeders;

use App\Models\CargoCategory;
use Illuminate\Database\Seeder;

class CargoCategorySeeder extends Seeder
{
    public function run(): void
    {
        CargoCategory::query()->updateOrCreate(
            ['code' => 'gasoline'],
            ['name' => '汽油', 'temperature_zone' => 'ambient', 'description' => '油品运输']
        );
        CargoCategory::query()->updateOrCreate(
            ['code' => 'diesel'],
            ['name' => '柴油', 'temperature_zone' => 'ambient', 'description' => '油品运输']
        );
        CargoCategory::query()->updateOrCreate(
            ['code' => 'seafood'],
            ['name' => '海鲜', 'temperature_zone' => 'cold', 'description' => '生鲜冷链']
        );
        CargoCategory::query()->updateOrCreate(
            ['code' => 'vegetable'],
            ['name' => '蔬菜', 'temperature_zone' => 'cold', 'description' => '生鲜冷链']
        );
    }
}


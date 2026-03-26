<?php

namespace Database\Seeders;

use App\Models\CargoCategory;
use App\Models\LogisticsSite;
use App\Models\PrePlanOrder;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class MockDataSeeder extends Seeder
{
    public function run(): void
    {
        // 扩展人员 mock 数据：额外司机与调度员
        User::factory()->count(2)->create([
            'role' => 'dispatcher',
            'status' => 'active',
            'password' => Hash::make('TaskRoute@123'),
        ]);
        User::factory()->count(6)->create([
            'role' => 'driver',
            'status' => 'active',
            'password' => Hash::make('TaskRoute@123'),
        ]);

        // 扩展资源 mock 数据：车辆、站点、货品、计划单
        CargoCategory::factory()->count(4)->create();
        Vehicle::factory()->count(8)->create(['status' => 'idle']);
        LogisticsSite::factory()->count(10)->create(['status' => 'active']);
        PrePlanOrder::factory()->count(20)->create(['status' => 'pending']);
    }
}


<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserAccountSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['account' => 'admin'],
            [
                'name' => '系统管理员',
                'phone' => '13800000001',
                'role' => 'admin',
                'status' => 'active',
                'password' => Hash::make('admin'),
            ]
        );

        User::query()->updateOrCreate(
            ['account' => 'dispatcher'],
            [
                'name' => '调度员A',
                'phone' => '13800000002',
                'role' => 'dispatcher',
                'status' => 'active',
                'password' => Hash::make('TaskRoute@123'),
            ]
        );

        User::query()->updateOrCreate(
            ['account' => 'driver'],
            [
                'name' => '司机A',
                'phone' => '13800000003',
                'role' => 'driver',
                'status' => 'active',
                'password' => Hash::make('TaskRoute@123'),
            ]
        );

        User::query()->updateOrCreate(
            ['account' => 'driver2'],
            [
                'name' => '司机B',
                'phone' => '13800000004',
                'role' => 'driver',
                'status' => 'active',
                'password' => Hash::make('TaskRoute@123'),
            ]
        );
    }
}


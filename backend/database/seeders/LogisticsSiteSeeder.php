<?php

namespace Database\Seeders;

use App\Models\LogisticsSite;
use Illuminate\Database\Seeder;

class LogisticsSiteSeeder extends Seeder
{
    public function run(): void
    {
        $sites = [
            ['name' => '上海油库A', 'site_type' => 'pickup', 'region_code' => 'SH-PD', 'address' => '上海油库A', 'contact_person' => '王站长', 'contact_phone' => '13900000001'],
            ['name' => '上海加油站B', 'site_type' => 'dropoff', 'region_code' => 'SH-PD', 'address' => '上海加油站B', 'contact_person' => '李经理', 'contact_phone' => '13900000002'],
            ['name' => '上海冷链仓C', 'site_type' => 'pickup', 'region_code' => 'SH-JD', 'address' => '上海冷链仓C', 'contact_person' => '赵主管', 'contact_phone' => '13900000003'],
            ['name' => '上海商超门店D', 'site_type' => 'dropoff', 'region_code' => 'SH-PT', 'address' => '上海商超门店D', 'contact_person' => '陈店长', 'contact_phone' => '13900000004'],
            ['name' => '上海加油站E', 'site_type' => 'dropoff', 'region_code' => 'SH-MH', 'address' => '上海加油站E', 'contact_person' => '吴经理', 'contact_phone' => '13900000005'],
            ['name' => '上海油库F', 'site_type' => 'pickup', 'region_code' => 'SH-MH', 'address' => '上海油库F', 'contact_person' => '孙站长', 'contact_phone' => '13900000006'],
            ['name' => '上海工地G', 'site_type' => 'dropoff', 'region_code' => 'SH-MH', 'address' => '上海工地G', 'contact_person' => '周主管', 'contact_phone' => '13900000007'],
            ['name' => '上海商超门店H', 'site_type' => 'dropoff', 'region_code' => 'SH-PT', 'address' => '上海商超门店H', 'contact_person' => '钱店长', 'contact_phone' => '13900000008'],
            ['name' => '上海分拣仓I', 'site_type' => 'pickup', 'region_code' => 'SH-JD', 'address' => '上海分拣仓I', 'contact_person' => '郑主管', 'contact_phone' => '13900000009'],
            ['name' => '上海商超门店J', 'site_type' => 'dropoff', 'region_code' => 'SH-PT', 'address' => '上海商超门店J', 'contact_person' => '王店长', 'contact_phone' => '13900000010'],
        ];

        foreach ($sites as $index => $site) {
            LogisticsSite::query()->updateOrCreate(
                ['name' => $site['name']],
                [
                    'site_no' => 'SITE-INIT-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                    'site_type' => $site['site_type'],
                    'organization_code' => 'SH',
                    'region_code' => $site['region_code'],
                    'address' => $site['address'],
                    'contact_person' => $site['contact_person'],
                    'contact_phone' => $site['contact_phone'],
                    'status' => 'active',
                ]
            );
        }
    }
}

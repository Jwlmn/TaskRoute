<?php

namespace Database\Seeders;

use App\Models\LogisticsSite;
use Illuminate\Database\Seeder;

class LogisticsSiteSeeder extends Seeder
{
    public function run(): void
    {
        $sites = [
            ['name' => '上海油库A', 'site_type' => 'pickup', 'address' => '上海市浦东新区油库A', 'contact_person' => '王站长', 'contact_phone' => '13900000001'],
            ['name' => '上海加油站B', 'site_type' => 'dropoff', 'address' => '上海市浦东新区加油站B', 'contact_person' => '李经理', 'contact_phone' => '13900000002'],
            ['name' => '上海冷链仓C', 'site_type' => 'pickup', 'address' => '上海市嘉定区冷链仓C', 'contact_person' => '赵主管', 'contact_phone' => '13900000003'],
            ['name' => '上海商超门店D', 'site_type' => 'dropoff', 'address' => '上海市普陀区门店D', 'contact_person' => '陈店长', 'contact_phone' => '13900000004'],
        ];

        foreach ($sites as $index => $site) {
            LogisticsSite::query()->updateOrCreate(
                ['name' => $site['name']],
                [
                    'site_no' => 'SITE-INIT-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                    'site_type' => $site['site_type'],
                    'address' => $site['address'],
                    'contact_person' => $site['contact_person'],
                    'contact_phone' => $site['contact_phone'],
                    'status' => 'active',
                ]
            );
        }
    }
}


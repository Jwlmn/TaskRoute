<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            UserAccountSeeder::class,
            CargoCategorySeeder::class,
            VehicleResourceSeeder::class,
            LogisticsSiteSeeder::class,
            CargoRuleSeeder::class,
            FreightRateTemplateSeeder::class,
            PrePlanOrderSeeder::class,
            MockDataSeeder::class,
        ]);
    }
}

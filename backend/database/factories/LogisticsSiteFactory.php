<?php

namespace Database\Factories;

use App\Models\LogisticsSite;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LogisticsSite>
 */
class LogisticsSiteFactory extends Factory
{
    protected $model = LogisticsSite::class;

    public function definition(): array
    {
        $name = fake()->city().fake()->randomElement(['配送中心', '仓库', '门店', '站点']);

        return [
            'site_no' => 'SITE-MOCK-'.Str::upper(Str::random(6)),
            'name' => $name,
            'site_type' => fake()->randomElement(['pickup', 'dropoff', 'both']),
            'contact_person' => fake()->name(),
            'contact_phone' => '13'.fake()->numerify('#########'),
            'address' => fake()->address(),
            'lng' => fake()->randomFloat(7, 120, 122),
            'lat' => fake()->randomFloat(7, 30, 32),
            'status' => fake()->randomElement(['active', 'inactive']),
        ];
    }
}


<?php

namespace Database\Factories;

use App\Models\CargoCategory;
use App\Models\PrePlanOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PrePlanOrder>
 */
class PrePlanOrderFactory extends Factory
{
    protected $model = PrePlanOrder::class;

    public function definition(): array
    {
        $pickupTime = fake()->dateTimeBetween('+1 hour', '+2 days');
        $deliveryTime = (clone $pickupTime)->modify('+'.fake()->numberBetween(1, 6).' hours');

        return [
            'order_no' => 'PO-MOCK-'.Str::upper(Str::random(8)),
            'cargo_category_id' => CargoCategory::query()->inRandomOrder()->value('id')
                ?? CargoCategory::factory()->create()->id,
            'client_name' => fake()->company(),
            'pickup_address' => fake()->address(),
            'pickup_contact_name' => fake()->name(),
            'pickup_contact_phone' => fake()->numerify('13#########'),
            'dropoff_address' => fake()->address(),
            'dropoff_contact_name' => fake()->name(),
            'dropoff_contact_phone' => fake()->numerify('13#########'),
            'cargo_weight_kg' => fake()->randomFloat(2, 100, 10000),
            'cargo_volume_m3' => fake()->randomFloat(2, 1, 30),
            'expected_pickup_at' => $pickupTime,
            'expected_delivery_at' => $deliveryTime,
            'status' => fake()->randomElement(['pending', 'scheduled']),
            'meta' => [
                'priority' => fake()->randomElement(['normal', 'high']),
            ],
        ];
    }
}

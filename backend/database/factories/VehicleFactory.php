<?php

namespace Database\Factories;

use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    protected $model = Vehicle::class;

    public function definition(): array
    {
        $prefix = fake()->randomElement(['沪A', '沪B', '沪C', '苏A']);
        $suffix = strtoupper(fake()->bothify('##??#'));

        return [
            'plate_number' => $prefix.$suffix,
            'name' => fake()->randomElement(['厢式车', '冷链车', '罐车', '平板车']).fake()->numberBetween(1, 99).'号',
            'vehicle_type' => fake()->randomElement(['van', 'coldchain', 'tank', 'flatbed']),
            'max_weight_kg' => fake()->numberBetween(3000, 20000),
            'max_volume_m3' => fake()->randomFloat(2, 8, 45),
            'status' => fake()->randomElement(['idle', 'busy', 'maintenance']),
            'meta' => [
                'energy' => fake()->randomElement(['diesel', 'gasoline', 'electric']),
            ],
        ];
    }
}


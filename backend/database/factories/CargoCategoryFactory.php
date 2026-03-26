<?php

namespace Database\Factories;

use App\Models\CargoCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CargoCategory>
 */
class CargoCategoryFactory extends Factory
{
    protected $model = CargoCategory::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            '汽油',
            '柴油',
            '海鲜',
            '蔬菜',
            '水果',
            '日化品',
        ]).'_'.fake()->unique()->numberBetween(100, 999);

        return [
            'name' => $name,
            'code' => Str::slug($name, '_'),
            'temperature_zone' => fake()->randomElement(['ambient', 'cold']),
            'description' => fake()->sentence(),
        ];
    }
}


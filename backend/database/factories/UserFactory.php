<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            $user->syncRoleAndPermissions();
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account' => fake()->unique()->userName(),
            'name' => fake()->name(),
            'role' => fake()->randomElement(['dispatcher', 'driver', 'customer']),
            'status' => 'active',
            'data_scope_type' => 'all',
            'data_scope' => null,
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Keep compatibility with default factory API.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => $attributes);
    }
}

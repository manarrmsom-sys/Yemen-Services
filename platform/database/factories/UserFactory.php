<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '+9677'.fake()->unique()->numerify('########'),
            'email' => fake()->unique()->safeEmail(),
            'role' => 'customer',
            'status' => 'active',
            'preferred_locale' => 'ar',
            'phone_verified_at' => now(),
            'password' => null,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => 'admin', 'password' => Hash::make('password')]);
    }

    public function dispatcher(): static
    {
        return $this->state(fn () => ['role' => 'dispatcher']);
    }

    public function tradesperson(): static
    {
        return $this->state(fn () => ['role' => 'tradesperson']);
    }
}

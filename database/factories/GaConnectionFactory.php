<?php

namespace Database\Factories;

use App\Models\GaConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GaConnection>
 */
class GaConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'google_id' => $this->faker->unique()->numerify('##########'),
            'google_email' => $this->faker->unique()->safeEmail(),
            'google_name' => $this->faker->name(),
            'access_token' => $this->faker->sha256(),
            'refresh_token' => $this->faker->sha256(),
            'token_expires_at' => now()->addHour(),
            'scopes' => 'analytics.readonly',
            'is_active' => true,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'token_expires_at' => now()->subMinutes(5),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}

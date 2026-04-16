<?php

namespace Database\Factories;

use App\Models\GaProperty;
use App\Models\PropertySnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertySnapshot>
 */
class PropertySnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ga_property_id' => GaProperty::factory(),
            'snapshot_date' => now()->subDay()->toDateString(),
            'period' => 'daily',
            'users' => $this->faker->numberBetween(50, 5000),
            'sessions' => $this->faker->numberBetween(60, 7000),
            'pageviews' => $this->faker->numberBetween(100, 20000),
            'bounce_rate' => $this->faker->randomFloat(2, 20, 80),
            'avg_session_duration' => $this->faker->numberBetween(30, 600),
            'top_sources' => [],
            'users_delta_wow' => $this->faker->randomFloat(2, -50, 50),
            'sessions_delta_wow' => $this->faker->randomFloat(2, -50, 50),
            'pageviews_delta_wow' => $this->faker->randomFloat(2, -50, 50),
            'bounce_delta_wow' => $this->faker->randomFloat(2, -20, 20),
            'users_delta_30d' => $this->faker->randomFloat(2, -50, 50),
            'sessions_delta_30d' => $this->faker->randomFloat(2, -50, 50),
            'trend' => $this->faker->randomElement(['spike', 'improved', 'stall', 'declined', 'drop']),
            'trend_score' => $this->faker->randomFloat(2, -100, 100),
            'is_spike' => false,
            'is_drop' => false,
            'is_stall' => false,
        ];
    }

    public function spike(): static
    {
        return $this->state(fn () => [
            'users_delta_wow' => $this->faker->randomFloat(2, 51, 100),
            'trend' => 'spike',
            'trend_score' => $this->faker->randomFloat(2, 60, 100),
            'is_spike' => true,
        ]);
    }

    public function drop(): static
    {
        return $this->state(fn () => [
            'users_delta_wow' => $this->faker->randomFloat(2, -100, -31),
            'trend' => 'drop',
            'trend_score' => $this->faker->randomFloat(2, -100, -40),
            'is_drop' => true,
        ]);
    }
}

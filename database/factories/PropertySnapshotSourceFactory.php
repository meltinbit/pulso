<?php

namespace Database\Factories;

use App\Models\PropertySnapshot;
use App\Models\PropertySnapshotSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertySnapshotSource>
 */
class PropertySnapshotSourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_snapshot_id' => PropertySnapshot::factory(),
            'source' => $this->faker->randomElement(['google', 'direct', 'facebook', 'twitter', 'bing']),
            'medium' => $this->faker->randomElement(['organic', 'referral', 'cpc', '(none)', 'social']),
            'sessions' => $this->faker->numberBetween(10, 1000),
            'users' => $this->faker->numberBetween(5, 800),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\PropertySnapshot;
use App\Models\PropertySnapshotSearchQuery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertySnapshotSearchQuery>
 */
class PropertySnapshotSearchQueryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_snapshot_id' => PropertySnapshot::factory(),
            'query' => $this->faker->words(3, true),
            'page' => '/'.$this->faker->slug(2),
            'clicks' => $this->faker->numberBetween(1, 500),
            'impressions' => $this->faker->numberBetween(50, 10000),
            'ctr' => $this->faker->randomFloat(2, 0.5, 15),
            'position' => $this->faker->randomFloat(1, 1, 50),
        ];
    }
}

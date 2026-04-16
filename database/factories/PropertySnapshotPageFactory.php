<?php

namespace Database\Factories;

use App\Models\PropertySnapshot;
use App\Models\PropertySnapshotPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertySnapshotPage>
 */
class PropertySnapshotPageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_snapshot_id' => PropertySnapshot::factory(),
            'page_path' => '/'.$this->faker->slug(2),
            'page_title' => $this->faker->sentence(3),
            'pageviews' => $this->faker->numberBetween(10, 5000),
            'users' => $this->faker->numberBetween(5, 3000),
            'bounce_rate' => $this->faker->randomFloat(2, 20, 90),
            'avg_engagement_time' => $this->faker->numberBetween(10, 300),
            'engagement_rate' => $this->faker->randomFloat(2, 30, 90),
        ];
    }
}

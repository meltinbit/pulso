<?php

namespace Database\Factories;

use App\Models\PropertySnapshot;
use App\Models\PropertySnapshotEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertySnapshotEvent>
 */
class PropertySnapshotEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'property_snapshot_id' => PropertySnapshot::factory(),
            'event_name' => $this->faker->randomElement([
                'page_view', 'session_start', 'scroll', 'click',
                'calcolo_eseguito', 'feedback_calcolatore', 'form_submit',
            ]),
            'event_count' => $this->faker->numberBetween(10, 5000),
            'total_users' => $this->faker->numberBetween(5, 3000),
        ];
    }
}

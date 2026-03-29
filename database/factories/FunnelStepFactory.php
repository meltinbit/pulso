<?php

namespace Database\Factories;

use App\Models\Funnel;
use App\Models\FunnelStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FunnelStep>
 */
class FunnelStepFactory extends Factory
{
    private static array $eventNames = [
        'first_open', 'first_visit', 'session_start', 'page_view',
        'sign_up', 'login', 'purchase', 'add_to_cart', 'begin_checkout',
    ];

    public function definition(): array
    {
        return [
            'funnel_id' => Funnel::factory(),
            'order' => 1,
            'name' => $this->faker->words(2, true),
            'event_name' => $this->faker->randomElement(self::$eventNames),
        ];
    }
}

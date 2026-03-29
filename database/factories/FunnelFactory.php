<?php

namespace Database\Factories;

use App\Models\Funnel;
use App\Models\GaProperty;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Funnel>
 */
class FunnelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'ga_property_id' => GaProperty::factory(),
            'name' => $this->faker->sentence(3),
            'description' => $this->faker->optional()->sentence(),
            'is_open' => false,
            'is_active' => true,
        ];
    }
}

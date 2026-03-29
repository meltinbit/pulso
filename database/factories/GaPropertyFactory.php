<?php

namespace Database\Factories;

use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GaProperty>
 */
class GaPropertyFactory extends Factory
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
            'ga_connection_id' => GaConnection::factory(),
            'property_id' => $this->faker->unique()->numerify('#########'),
            'display_name' => $this->faker->company(),
            'website_url' => $this->faker->url(),
            'timezone' => 'Europe/Rome',
            'currency' => 'EUR',
            'is_active' => true,
        ];
    }
}

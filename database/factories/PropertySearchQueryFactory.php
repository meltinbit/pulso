<?php

namespace Database\Factories;

use App\Models\GaProperty;
use App\Models\PropertySearchQuery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertySearchQuery>
 */
class PropertySearchQueryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ga_property_id' => GaProperty::factory(),
            'date' => $this->faker->dateTimeBetween('-30 days', '-1 day')->format('Y-m-d'),
            'query' => $this->faker->words(3, true),
            'page' => 'https://example.com/'.$this->faker->slug(2),
            'clicks' => $this->faker->numberBetween(1, 500),
            'impressions' => $this->faker->numberBetween(50, 10000),
            'ctr' => $this->faker->randomFloat(2, 0.5, 15),
            'position' => $this->faker->randomFloat(1, 1, 50),
        ];
    }
}

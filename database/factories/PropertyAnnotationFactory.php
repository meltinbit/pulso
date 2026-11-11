<?php

namespace Database\Factories;

use App\Models\GaProperty;
use App\Models\PropertyAnnotation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyAnnotation>
 */
class PropertyAnnotationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ga_property_id' => GaProperty::factory(),
            'date' => fake()->dateTimeBetween('-30 days')->format('Y-m-d'),
            'type' => fake()->randomElement(PropertyAnnotation::TYPES),
            'description' => fake()->sentence(),
            'urls' => null,
            'agent' => null,
        ];
    }
}

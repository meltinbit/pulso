<?php

namespace Database\Factories;

use App\Models\AiVisibilityPrompt;
use App\Models\GaProperty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiVisibilityPrompt>
 */
class AiVisibilityPromptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ga_property_id' => GaProperty::factory(),
            'prompt' => fake()->unique()->sentence().'?',
            'is_active' => true,
            'agent' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}

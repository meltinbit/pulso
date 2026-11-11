<?php

namespace Database\Factories;

use App\Models\McpCallLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<McpCallLog>
 */
class McpCallLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tool' => fake()->randomElement(['list-properties-tool', 'get-property-summary-tool', 'get-property-search-queries-tool']),
            'agent' => fake()->randomElement(['seo', 'ads', null]),
            'arguments' => ['property_id' => fake()->numberBetween(1, 5)],
            'response_bytes' => fake()->numberBetween(500, 20000),
            'is_error' => false,
            'error' => null,
            'duration_ms' => fake()->numberBetween(20, 900),
            'created_at' => now(),
        ];
    }

    public function failed(string $error = 'Something went wrong.'): static
    {
        return $this->state(fn () => [
            'is_error' => true,
            'error' => $error,
        ]);
    }
}

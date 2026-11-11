<?php

namespace Database\Factories;

use App\Models\AiVisibilityCheck;
use App\Models\AiVisibilityPrompt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiVisibilityCheck>
 */
class AiVisibilityCheckFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_visibility_prompt_id' => AiVisibilityPrompt::factory(),
            'ga_property_id' => fn (array $attributes) => AiVisibilityPrompt::find($attributes['ai_visibility_prompt_id'])->ga_property_id,
            'run_date' => now()->toDateString(),
            'engine' => 'openai',
            'model' => 'gpt-5.6-luna',
            'mentioned' => false,
            'position' => null,
            'cited_url' => null,
            'cited_domains' => ['example.org'],
            'answer_excerpt' => fake()->paragraph(),
            'input_tokens' => 5000,
            'output_tokens' => 500,
            'searches' => 1,
            'cost_usd' => 0.0116,
        ];
    }

    public function mentioned(int $position = 1, string $url = 'https://example.com/'): static
    {
        return $this->state(fn (): array => ['mentioned' => true, 'position' => $position, 'cited_url' => $url]);
    }
}

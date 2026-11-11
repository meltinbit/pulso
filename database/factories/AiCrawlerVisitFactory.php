<?php

namespace Database\Factories;

use App\Models\AiCrawlerVisit;
use App\Models\GaProperty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiCrawlerVisit>
 */
class AiCrawlerVisitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $path = '/'.fake()->slug(2).'/';

        return [
            'ga_property_id' => GaProperty::factory(),
            'date' => now()->toDateString(),
            'bot' => 'GPTBot',
            'path' => $path,
            'path_hash' => sha1($path),
            'hits' => 1,
            'last_status' => 200,
            'last_seen_at' => now(),
        ];
    }
}

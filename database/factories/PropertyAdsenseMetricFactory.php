<?php

namespace Database\Factories;

use App\Models\GaProperty;
use App\Models\PropertyAdsenseMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyAdsenseMetric>
 */
class PropertyAdsenseMetricFactory extends Factory
{
    public function definition(): array
    {
        $adRequests = $this->faker->numberBetween(100, 5000);

        return [
            'ga_property_id' => GaProperty::factory(),
            'date' => $this->faker->dateTimeBetween('-30 days', '-1 day')->format('Y-m-d'),
            'dimension' => PropertyAdsenseMetric::DIMENSION_TOTAL,
            'dimension_value' => '',
            'earnings' => $this->faker->randomFloat(2, 0.5, 50),
            'page_views' => $this->faker->numberBetween(100, 5000),
            'impressions' => $this->faker->numberBetween(100, 8000),
            'clicks' => $this->faker->numberBetween(0, 100),
            'ad_requests' => $adRequests,
            'matched_ad_requests' => (int) ($adRequests * 0.9),
        ];
    }

    public function breakdown(string $dimension, string $value): static
    {
        return $this->state(fn () => [
            'dimension' => $dimension,
            'dimension_value' => $value,
        ]);
    }
}

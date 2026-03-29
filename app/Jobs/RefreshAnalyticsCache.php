<?php

namespace App\Jobs;

use App\Models\GaProperty;
use App\Services\GaClientService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RefreshAnalyticsCache implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(GaClientService $ga): void
    {
        $properties = GaProperty::where('is_active', true)
            ->whereHas('gaConnection', fn ($q) => $q->where('is_active', true))
            ->with('gaConnection')
            ->get();

        foreach ($properties as $property) {
            try {
                $this->refreshPropertyCache($ga, $property);
            } catch (\Throwable $e) {
                Log::warning("Failed to refresh cache for property {$property->property_id}: {$e->getMessage()}");
            }
        }
    }

    private function refreshPropertyCache(GaClientService $ga, GaProperty $property): void
    {
        // Overview: users & sessions last 30 days
        $ga->runReport($property, [
            'dateRanges' => [
                ['startDate' => '30daysAgo', 'endDate' => 'today'],
                ['startDate' => '60daysAgo', 'endDate' => '31daysAgo'],
            ],
            'dimensions' => [['name' => 'date']],
            'metrics' => [
                ['name' => 'activeUsers'],
                ['name' => 'sessions'],
                ['name' => 'bounceRate'],
                ['name' => 'averageSessionDuration'],
            ],
        ]);

        // Top 10 pages
        $ga->runReport($property, [
            'dateRanges' => [['startDate' => '30daysAgo', 'endDate' => 'today']],
            'dimensions' => [['name' => 'pagePath'], ['name' => 'pageTitle']],
            'metrics' => [['name' => 'screenPageViews'], ['name' => 'activeUsers']],
            'limit' => 10,
        ]);

        // Traffic sources
        $ga->runReport($property, [
            'dateRanges' => [['startDate' => '30daysAgo', 'endDate' => 'today']],
            'dimensions' => [['name' => 'sessionDefaultChannelGroup']],
            'metrics' => [['name' => 'sessions'], ['name' => 'activeUsers']],
        ]);
    }
}

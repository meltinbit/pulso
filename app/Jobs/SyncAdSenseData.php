<?php

namespace App\Jobs;

use App\Models\GaProperty;
use App\Services\AdSenseSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Re-downloads the last few days of AdSense metrics for every active property
 * whose Google connection granted AdSense access, because estimated earnings
 * keep being adjusted after the day ends.
 */
class SyncAdSenseData implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public int $days = AdSenseSyncService::RECENT_DAYS,
    ) {}

    public function handle(AdSenseSyncService $sync): void
    {
        GaProperty::query()
            ->where('is_active', true)
            ->whereNotNull('website_url')
            ->whereHas('gaConnection', fn ($query) => $query->where('is_active', true)->where('scopes', 'like', '%adsense%'))
            ->with('gaConnection')
            ->each(function (GaProperty $property) use ($sync): void {
                try {
                    $stored = $sync->syncRecent($property, $this->days);
                    Log::info("AdSense synced for {$property->display_name}: {$stored} days");
                } catch (\Throwable $e) {
                    Log::warning("AdSense sync failed for {$property->display_name}: {$e->getMessage()}");
                }
            });
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("SyncAdSenseData job failed: {$exception->getMessage()}");
    }
}

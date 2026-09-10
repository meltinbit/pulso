<?php

namespace App\Jobs;

use App\Models\GaProperty;
use App\Services\SearchConsoleSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Re-downloads the last few days of Search Console data for every active
 * property, because Google keeps consolidating a day for several days after it
 * is first published.
 */
class SyncSearchConsoleData implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public int $days = SearchConsoleSyncService::RECENT_DAYS,
    ) {}

    public function handle(SearchConsoleSyncService $sync): void
    {
        GaProperty::query()
            ->where('is_active', true)
            ->whereHas('gaConnection', fn ($query) => $query->where('is_active', true))
            ->with('gaConnection')
            ->each(function (GaProperty $property) use ($sync): void {
                try {
                    $stored = $sync->syncRecent($property, $this->days);
                    Log::info("Search Console synced for {$property->display_name}: {$stored} rows over the last {$this->days} days");
                } catch (\Throwable $e) {
                    Log::warning("Search Console sync failed for {$property->display_name}: {$e->getMessage()}");
                }
            });
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("SyncSearchConsoleData job failed: {$exception->getMessage()}");
    }
}

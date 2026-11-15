<?php

namespace App\Jobs;

use App\Models\GaProperty;
use App\Services\SearchConsoleSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Downloads the whole Search Console history (16 months) of a single property,
 * queued because it takes one Search Analytics call per week.
 */
class BackfillSearchConsoleData implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public GaProperty $property,
    ) {}

    public function handle(SearchConsoleSyncService $sync): void
    {
        $stored = $sync->backfill($this->property);

        Log::info("Search Console backfill for {$this->property->display_name}: {$stored} rows");
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("BackfillSearchConsoleData failed for {$this->property->display_name}: {$exception->getMessage()}");
    }
}

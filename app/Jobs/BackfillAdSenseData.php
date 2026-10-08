<?php

namespace App\Jobs;

use App\Models\GaProperty;
use App\Services\AdSenseSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Downloads the AdSense history of a single property, queued because a long
 * window needs several report calls per month.
 */
class BackfillAdSenseData implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public GaProperty $property,
        public int $months = AdSenseSyncService::BACKFILL_MONTHS,
    ) {}

    public function handle(AdSenseSyncService $sync): void
    {
        $sync->markBackfill($this->property, 'running');

        $stored = $sync->backfill($this->property, $this->months);

        $sync->markBackfill($this->property, 'done', ['days' => $stored]);

        Log::info("AdSense backfill for {$this->property->display_name}: {$stored} days over {$this->months} months");
    }

    public function failed(\Throwable $exception): void
    {
        app(AdSenseSyncService::class)->markBackfill($this->property, 'failed', ['error' => $exception->getMessage()]);

        Log::error("BackfillAdSenseData failed for {$this->property->display_name}: {$exception->getMessage()}");
    }
}

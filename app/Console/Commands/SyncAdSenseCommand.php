<?php

namespace App\Console\Commands;

use App\Models\GaProperty;
use App\Services\AdSenseSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SyncAdSenseCommand extends Command
{
    protected $signature = 'adsense:sync
        {--property= : Sync only this property ID}
        {--days= : Re-download the last N days (defaults to '.AdSenseSyncService::RECENT_DAYS.')}
        {--from= : Start date (YYYY-MM-DD), overrides --days}
        {--to= : End date (YYYY-MM-DD), defaults to today}
        {--backfill : Download the history window}
        {--months= : Months downloaded by --backfill (defaults to '.AdSenseSyncService::BACKFILL_MONTHS.')}';

    protected $description = 'Sync Google AdSense metrics per day for active properties';

    public function handle(AdSenseSyncService $sync): int
    {
        $properties = GaProperty::query()
            ->where('is_active', true)
            ->whereNotNull('website_url')
            ->whereHas('gaConnection', fn ($query) => $query->where('is_active', true)->where('scopes', 'like', '%adsense%'))
            ->when($this->option('property'), fn ($query, $id) => $query->where('id', $id))
            ->with('gaConnection')
            ->get();

        if ($properties->isEmpty()) {
            $this->warn('No active properties with AdSense access found. Reconnect the Google account to grant it.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($properties as $property) {
            $this->line("  {$property->display_name}...");

            try {
                $stored = $this->syncProperty($sync, $property);
                $this->info("    {$stored} days stored.");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("    {$e->getMessage()}");
                Log::warning("AdSense sync failed for {$property->display_name}: {$e->getMessage()}");
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function syncProperty(AdSenseSyncService $sync, GaProperty $property): int
    {
        if ($this->option('backfill')) {
            return $sync->backfill($property, (int) ($this->option('months') ?: AdSenseSyncService::BACKFILL_MONTHS));
        }

        if ($this->option('from')) {
            $to = $this->option('to') ? Carbon::parse($this->option('to')) : Carbon::today($property->timezone ?: 'UTC');

            return $sync->syncRange($property, Carbon::parse($this->option('from')), $to);
        }

        return $sync->syncRecent($property, (int) ($this->option('days') ?: AdSenseSyncService::RECENT_DAYS));
    }
}

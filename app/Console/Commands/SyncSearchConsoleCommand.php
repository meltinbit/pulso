<?php

namespace App\Console\Commands;

use App\Models\GaProperty;
use App\Services\SearchConsoleSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SyncSearchConsoleCommand extends Command
{
    protected $signature = 'search-console:sync
        {--property= : Sync only this property ID}
        {--days= : Re-download the last N days (defaults to '.SearchConsoleSyncService::RECENT_DAYS.')}
        {--from= : Start date (YYYY-MM-DD), overrides --days}
        {--to= : End date (YYYY-MM-DD), defaults to yesterday}
        {--backfill : Download the full 16 months Google retains}';

    protected $description = 'Sync Google Search Console query/page rows per day for active properties';

    public function handle(SearchConsoleSyncService $sync): int
    {
        $properties = GaProperty::query()
            ->where('is_active', true)
            ->whereHas('gaConnection', fn ($query) => $query->where('is_active', true))
            ->when($this->option('property'), fn ($query, $id) => $query->where('id', $id))
            ->with('gaConnection')
            ->get();

        if ($properties->isEmpty()) {
            $this->warn('No active properties found.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($properties as $property) {
            $this->line("  {$property->display_name}...");

            try {
                $stored = $this->syncProperty($sync, $property);
                $this->info("    {$stored} rows stored.");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("    {$e->getMessage()}");
                Log::warning("Search Console sync failed for {$property->display_name}: {$e->getMessage()}");
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function syncProperty(SearchConsoleSyncService $sync, GaProperty $property): int
    {
        if ($this->option('backfill')) {
            return $sync->backfill($property);
        }

        if ($this->option('from')) {
            $to = $this->option('to') ? Carbon::parse($this->option('to')) : Carbon::yesterday('UTC');

            return $sync->syncRange($property, Carbon::parse($this->option('from')), $to);
        }

        return $sync->syncRecent($property, (int) ($this->option('days') ?: SearchConsoleSyncService::RECENT_DAYS));
    }
}

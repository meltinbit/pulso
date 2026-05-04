<?php

namespace App\Console\Commands;

use App\Models\GaProperty;
use App\Services\SnapshotAnalyzerService;
use App\Services\TelegramNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class GenerateSnapshotsCommand extends Command
{
    protected $signature = 'snapshots:generate
        {--from= : Start date (YYYY-MM-DD, defaults to yesterday)}
        {--to= : End date (YYYY-MM-DD, defaults to --from date)}
        {--property= : Generate only for a specific property ID}
        {--no-telegram : Skip sending the Telegram digest}
        {--auto-backfill : Automatically backfill missing snapshots from the last available snapshot}';

    protected $description = 'Generate daily snapshots for GA4 properties, supports date ranges for backfilling';

    public function handle(SnapshotAnalyzerService $analyzer, TelegramNotificationService $telegram): int
    {
        // Check if auto-backfill is enabled
        $autoBackfill = $this->option('auto-backfill');
        
        // Determine the date range
        $fromOption = $this->option('from');
        $toOption = $this->option('to');
        $defaultDate = Carbon::yesterday();
        
        // Get active properties
        $query = GaProperty::where('is_active', true)
            ->whereHas('gaConnection', fn ($q) => $q->where('is_active', true))
            ->with('gaConnection');

        if ($this->option('property')) {
            $query->where('id', $this->option('property'));
        }

        $properties = $query->get();

        if ($properties->isEmpty()) {
            $this->warn('No active properties found.');
            return self::SUCCESS;
        }
        
        // Handle different modes: manual date range or auto-backfill
        if (!$autoBackfill) {
            // Manual date range mode
            $from = $fromOption ? Carbon::parse($fromOption) : $defaultDate;
            $to = $toOption ? Carbon::parse($toOption) : $from->copy();
            
            if ($to->lt($from)) {
                $this->error('--to must be after or equal to --from.');
                return self::FAILURE;
            }
            
            $days = $from->diffInDays($to) + 1;
            $this->info("Generating snapshots from {$from->toDateString()} to {$to->toDateString()} ({$days} days, {$properties->count()} properties)...");
        } else {
            // Auto-backfill mode - analyze each property for missing snapshots
            $this->info("Auto-backfill enabled. Checking for missing snapshots for {$properties->count()} properties...");
            
            // If specific dates were provided with auto-backfill, warn the user
            if ($fromOption || $toOption) {
                $this->warn('Note: --auto-backfill will override --from and --to options, finding missing dates automatically.');
            }
        }

        $totalGenerated = 0;
        $totalFailed = 0;

        // Process each property
        foreach ($properties as $property) {
            if ($autoBackfill) {
                // Auto-backfill mode: Find the last snapshot and fill the gap
                $latestSnapshot = $property->snapshots()->latest('snapshot_date')->first();
                
                if ($latestSnapshot) {
                    $from = Carbon::parse($latestSnapshot->snapshot_date)->addDay();
                    $to = $defaultDate;
                    
                    if ($from->isAfter($to)) {
                        $this->line("  {$property->display_name}: No missing snapshots to generate.");
                        continue;
                    }
                    
                    $this->info("  {$property->display_name}: Generating missing snapshots from {$from->toDateString()} to {$to->toDateString()}");
                } else {
                    // No snapshots exist, create from 30 days ago to yesterday (limit the backfill)
                    $from = Carbon::today()->subDays(30);
                    $to = $defaultDate;
                    $this->info("  {$property->display_name}: No previous snapshots. Generating for last 30 days");
                }
            }
            
            // Generate snapshots for the date range
            for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
                try {
                    $snapshot = $analyzer->analyze($property, $date->copy());
                    $totalGenerated++;
                    $this->line("    {$date->toDateString()}: {$snapshot->trend} (score: {$snapshot->trend_score})");
                } catch (\Throwable $e) {
                    $totalFailed++;
                    $this->error("    {$date->toDateString()}: {$e->getMessage()}");
                    Log::warning("Failed to generate snapshot for property {$property->property_id} on {$date->toDateString()}: {$e->getMessage()}");
                }
            }
        }

        if ($totalGenerated > 0 && ! $this->option('no-telegram')) {
            // For auto-backfill or manual mode, we only want to send the telegram message for the most recent day
            $lastDay = $autoBackfill ? Carbon::yesterday() : $to;
            
            $lastDaySnapshots = $properties->map(
                fn ($p) => $p->snapshots()->where('snapshot_date', $lastDay->toDateString())->first()
            )->filter();

            if ($lastDaySnapshots->isNotEmpty()) {
                $telegram->sendDailyDigest($lastDaySnapshots, $lastDay);
                $this->info('Telegram digest sent.');
            }
        }

        $this->newLine();
        $this->info("Done. {$totalGenerated} generated, {$totalFailed} failed.");

        return $totalFailed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

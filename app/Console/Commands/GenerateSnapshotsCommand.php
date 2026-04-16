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
        {--no-telegram : Skip sending the Telegram digest}';

    protected $description = 'Generate daily snapshots for GA4 properties, supports date ranges for backfilling';

    public function handle(SnapshotAnalyzerService $analyzer, TelegramNotificationService $telegram): int
    {
        $from = $this->option('from')
            ? Carbon::parse($this->option('from'))
            : Carbon::yesterday();

        $to = $this->option('to')
            ? Carbon::parse($this->option('to'))
            : $from->copy();

        if ($to->lt($from)) {
            $this->error('--to must be after or equal to --from.');

            return self::FAILURE;
        }

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

        $days = $from->diffInDays($to) + 1;
        $this->info("Generating snapshots from {$from->toDateString()} to {$to->toDateString()} ({$days} days, {$properties->count()} properties)...");

        $totalGenerated = 0;
        $totalFailed = 0;

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $this->newLine();
            $this->info($date->toDateString());

            foreach ($properties as $property) {
                try {
                    $snapshot = $analyzer->analyze($property, $date->copy());
                    $totalGenerated++;
                    $this->line("  {$property->display_name}: {$snapshot->trend} (score: {$snapshot->trend_score})");
                } catch (\Throwable $e) {
                    $totalFailed++;
                    $this->error("  {$property->display_name}: {$e->getMessage()}");
                    Log::warning("Failed to generate snapshot for property {$property->property_id} on {$date->toDateString()}: {$e->getMessage()}");
                }
            }
        }

        if ($totalGenerated > 0 && ! $this->option('no-telegram')) {
            $lastDaySnapshots = $properties->map(
                fn ($p) => $p->snapshots()->where('snapshot_date', $to->toDateString())->first()
            )->filter();

            if ($lastDaySnapshots->isNotEmpty()) {
                $telegram->sendDailyDigest($lastDaySnapshots, $to);
                $this->info('Telegram digest sent.');
            }
        }

        $this->newLine();
        $this->info("Done. {$totalGenerated} generated, {$totalFailed} failed.");

        return $totalFailed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

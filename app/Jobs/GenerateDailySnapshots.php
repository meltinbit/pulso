<?php

namespace App\Jobs;

use App\Models\GaProperty;
use App\Models\PropertySnapshot;
use App\Services\SnapshotAnalyzerService;
use App\Services\TelegramNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class GenerateDailySnapshots implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function handle(SnapshotAnalyzerService $analyzer, TelegramNotificationService $telegram): void
    {
        $yesterday = Carbon::yesterday();

        $properties = GaProperty::where('is_active', true)
            ->whereHas('gaConnection', fn ($q) => $q->where('is_active', true))
            ->with('gaConnection')
            ->get();

        if ($properties->isEmpty()) {
            Log::info('GenerateDailySnapshots: no active properties found.');

            return;
        }

        /** @var Collection<int, PropertySnapshot> $snapshots */
        $snapshots = collect();

        foreach ($properties as $property) {
            try {
                $snapshot = $analyzer->analyze($property, $yesterday);
                $snapshots->push($snapshot);

                Log::info("Snapshot generated for property {$property->display_name} ({$property->property_id}): trend={$snapshot->trend}");
            } catch (\Throwable $e) {
                Log::warning("Failed to generate snapshot for property {$property->property_id}: {$e->getMessage()}");
            }
        }

        if ($snapshots->isNotEmpty()) {
            $telegram->sendDailyDigest($snapshots, $yesterday);
        }
    }
}

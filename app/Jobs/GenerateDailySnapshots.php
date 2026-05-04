<?php

namespace App\Jobs;

use App\Models\PropertySnapshot;
use App\Models\User;
use App\Services\SettingService;
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

    public function handle(SnapshotAnalyzerService $analyzer, TelegramNotificationService $telegram, SettingService $settings): void
    {
        $targetDate = Carbon::yesterday('UTC');
        $currentHour = now('UTC')->format('H');

        User::each(function (User $user) use ($analyzer, $telegram, $settings, $targetDate, $currentHour) {
            if ($settings->get($user->id, 'snapshot_enabled', '1') !== '1') {
                return;
            }

            $snapshotTime = $settings->get($user->id, 'snapshot_time', '09:00') ?? '09:00';

            if (substr($snapshotTime, 0, 2) !== $currentHour) {
                return;
            }

            $properties = $user->gaProperties()
                ->where('is_active', true)
                ->whereHas('gaConnection', fn ($q) => $q->where('is_active', true))
                ->with('gaConnection')
                ->get();

            if ($properties->isEmpty()) {
                return;
            }

            /** @var Collection<int, PropertySnapshot> $snapshots */
            $snapshots = collect();

            foreach ($properties as $property) {
                try {
                    // Check for the latest snapshot for this property
                    $latestSnapshot = $property->snapshots()->latest('snapshot_date')->first();

                    if ($latestSnapshot) {
                        $lastSnapshotDate = Carbon::parse($latestSnapshot->snapshot_date);
                        
                        // If there's a gap between the latest snapshot and yesterday
                        if ($lastSnapshotDate->lt($targetDate)) {
                            $currentDate = $lastSnapshotDate->copy()->addDay();
                            
                            // Generate all missing snapshots up to yesterday
                            while ($currentDate->lte($targetDate)) {
                                $snapshot = $analyzer->analyze($property, $currentDate->copy());
                                Log::info("Backfill snapshot generated for {$property->display_name} (user {$user->id}): date={$currentDate->toDateString()}, trend={$snapshot->trend}");
                                
                                // Only add yesterday's snapshot to the collection for Telegram digest
                                if ($currentDate->isSameDay($targetDate)) {
                                    $snapshots->push($snapshot);
                                }
                                
                                $currentDate->addDay();
                            }
                        } else {
                            // No gap, just ensure yesterday's snapshot exists
                            if (!$lastSnapshotDate->isSameDay($targetDate)) {
                                $snapshot = $analyzer->analyze($property, $targetDate);
                                $snapshots->push($snapshot);
                                Log::info("Snapshot generated for {$property->display_name} (user {$user->id}): trend={$snapshot->trend}");
                            }
                        }
                    } else {
                        // No previous snapshots, just create one for yesterday
                        $snapshot = $analyzer->analyze($property, $targetDate);
                        $snapshots->push($snapshot);
                        Log::info("Snapshot generated for {$property->display_name} (user {$user->id}): trend={$snapshot->trend}");
                    }
                } catch (\Throwable $e) {
                    Log::warning("Failed to generate snapshot for {$property->property_id} (user {$user->id}): {$e->getMessage()}");
                }
            }

            $sendTelegram = $settings->get($user->id, 'snapshot_telegram', '1') === '1';

            if ($snapshots->isNotEmpty() && $sendTelegram) {
                $telegram->sendDailyDigest($snapshots, $targetDate, $user->id);
            }
        });
    }
}

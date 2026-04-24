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
        $yesterday = Carbon::yesterday('UTC');
        $currentHour = now('UTC')->format('H');

        User::each(function (User $user) use ($analyzer, $telegram, $settings, $yesterday, $currentHour) {
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
                    $snapshot = $analyzer->analyze($property, $yesterday);
                    $snapshots->push($snapshot);

                    Log::info("Snapshot generated for {$property->display_name} (user {$user->id}): trend={$snapshot->trend}");
                } catch (\Throwable $e) {
                    Log::warning("Failed to generate snapshot for {$property->property_id} (user {$user->id}): {$e->getMessage()}");
                }
            }

            $sendTelegram = $settings->get($user->id, 'snapshot_telegram', '1') === '1';

            if ($snapshots->isNotEmpty() && $sendTelegram) {
                $telegram->sendDailyDigest($snapshots, $yesterday, $user->id);
            }
        });
    }
}

<?php

namespace App\Jobs;

use App\Exceptions\BingApiException;
use App\Models\GaProperty;
use App\Models\User;
use App\Services\BingSyncService;
use App\Services\SettingService;
use App\Services\TelegramNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Links new properties to their Bing sites and downloads Bing data for every
 * linked property. An invalid API key is reported once on Telegram.
 */
class SyncBingWebmasterData implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(
        public ?int $propertyId = null,
    ) {}

    public function handle(BingSyncService $bing, SettingService $settings, TelegramNotificationService $telegram): void
    {
        User::each(function (User $user) use ($bing, $settings, $telegram): void {
            $apiKey = $bing->apiKey($user);

            if ($apiKey === null) {
                return;
            }

            try {
                $bing->matchSites($user, $apiKey);

                $user->gaProperties()
                    ->where('is_active', true)
                    ->whereNotNull('bing_site_url')
                    ->when($this->propertyId, fn ($query, int $id) => $query->whereKey($id))
                    ->each(function (GaProperty $property) use ($bing, $apiKey): void {
                        try {
                            $counts = $bing->sync($property, $apiKey);
                            Log::info("Bing synced for {$property->display_name}: ".json_encode($counts));
                        } catch (BingApiException $e) {
                            if ($e->isAuthError) {
                                throw $e;
                            }

                            Log::warning("Bing sync failed for {$property->display_name}: {$e->getMessage()}");
                        }
                    });

                $settings->set($user->id, BingSyncService::ALERTED_SETTING_KEY, null, 'bing');
            } catch (BingApiException $e) {
                Log::warning("Bing sync failed for user {$user->id}: {$e->getMessage()}");

                if ($e->isAuthError && $settings->get($user->id, BingSyncService::ALERTED_SETTING_KEY) !== '1') {
                    if ($telegram->sendBingApiKeyAlert($user->id)) {
                        $settings->set($user->id, BingSyncService::ALERTED_SETTING_KEY, '1', 'bing');
                    }
                }
            }
        });
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("SyncBingWebmasterData job failed: {$exception->getMessage()}");
    }
}

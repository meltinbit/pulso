<?php

namespace App\Jobs;

use App\Exceptions\GoogleTokenExpiredException;
use App\Models\GaConnection;
use App\Services\GoogleTokenService;
use App\Services\SettingService;
use App\Services\TelegramNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CheckGoogleConnections implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(
        GoogleTokenService $googleTokens,
        TelegramNotificationService $telegram,
        SettingService $settings,
    ): void {
        GaConnection::query()
            ->where('is_active', true)
            ->with('user')
            ->each(function (GaConnection $connection) use ($googleTokens, $telegram, $settings) {
                try {
                    $googleTokens->getFreshToken($connection);
                    $settings->set($connection->user_id, $this->alertedSettingKey($connection->id), null, 'google');
                } catch (GoogleTokenExpiredException) {
                    if ($settings->get($connection->user_id, $this->alertedSettingKey($connection->id)) === '1') {
                        return;
                    }

                    $sent = $telegram->sendGoogleConnectionAlert(
                        $connection->user_id,
                        $connection->google_email,
                    );

                    if ($sent) {
                        $settings->set($connection->user_id, $this->alertedSettingKey($connection->id), '1', 'google');
                    }
                } catch (\Throwable $e) {
                    Log::warning("Failed to check Google connection {$connection->id}: {$e->getMessage()}");
                }
            });
    }

    private function alertedSettingKey(int $connectionId): string
    {
        return "google_connection_alerted_{$connectionId}";
    }
}

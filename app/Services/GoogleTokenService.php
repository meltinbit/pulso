<?php

namespace App\Services;

use App\Exceptions\GoogleTokenExpiredException;
use App\Models\GaConnection;
use Illuminate\Support\Facades\Http;

class GoogleTokenService
{
    public function __construct(
        private SettingService $settings,
    ) {}

    public function getFreshToken(GaConnection $connection): string
    {
        if (! $connection->isExpired()) {
            return $connection->access_token;
        }

        $response = Http::timeout(10)
            ->connectTimeout(5)
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => $this->settings->get($connection->user_id, 'google_client_id'),
                'client_secret' => $this->settings->get($connection->user_id, 'google_client_secret'),
                'refresh_token' => $connection->refresh_token,
                'grant_type' => 'refresh_token',
            ]);

        if ($response->failed()) {
            $connection->update(['is_active' => false]);

            throw new GoogleTokenExpiredException;
        }

        $data = $response->json();

        $connection->update([
            'access_token' => $data['access_token'],
            'token_expires_at' => now()->addSeconds($data['expires_in']),
        ]);

        return $data['access_token'];
    }
}

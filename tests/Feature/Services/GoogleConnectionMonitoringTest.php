<?php

use App\Exceptions\GoogleTokenExpiredException;
use App\Models\GaConnection;
use App\Services\GoogleTokenService;
use App\Services\SettingService;
use App\Services\TelegramNotificationService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->connection = GaConnection::factory()->expired()->create([
        'google_email' => 'monitor@example.com',
    ]);

    $settings = app(SettingService::class);
    $settings->set($this->connection->user_id, 'google_client_id', 'test-client-id', 'google', encrypted: true);
    $settings->set($this->connection->user_id, 'google_client_secret', 'test-client-secret', 'google', encrypted: true);
    $settings->set($this->connection->user_id, 'telegram_bot_token', 'test-bot-token', 'telegram', true);
    $settings->set($this->connection->user_id, 'telegram_chat_id', '12345', 'telegram');
});

test('google token service does not deactivate connection on transient refresh errors', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['error' => 'backend_error'], 500),
    ]);

    $service = app(GoogleTokenService::class);

    expect(fn () => $service->getFreshToken($this->connection))
        ->toThrow(\RuntimeException::class);

    $this->connection->refresh();
    expect($this->connection->is_active)->toBeTrue();
});

test('sendGoogleConnectionAlert sends a telegram message', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true]),
    ]);

    $result = app(TelegramNotificationService::class)->sendGoogleConnectionAlert(
        $this->connection->user_id,
        $this->connection->google_email,
    );

    expect($result)->toBeTrue();
    Http::assertSentCount(1);
});

test('google token service still deactivates connection on invalid_grant', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $service = app(GoogleTokenService::class);

    expect(fn () => $service->getFreshToken($this->connection))
        ->toThrow(GoogleTokenExpiredException::class);

    $this->connection->refresh();
    expect($this->connection->is_active)->toBeFalse();
});

<?php

use App\Jobs\CheckGoogleConnections;
use App\Models\GaConnection;
use App\Services\SettingService;
use App\Services\TelegramNotificationService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('job sends a telegram alert when a refresh token is invalid', function () {
    $connection = GaConnection::factory()->expired()->create([
        'google_email' => 'alerts@example.com',
    ]);

    app(SettingService::class)->set($connection->user_id, 'google_client_id', 'test-client-id', 'google', encrypted: true);
    app(SettingService::class)->set($connection->user_id, 'google_client_secret', 'test-client-secret', 'google', encrypted: true);
    app(SettingService::class)->set($connection->user_id, 'telegram_bot_token', 'test-bot-token', 'telegram', true);
    app(SettingService::class)->set($connection->user_id, 'telegram_chat_id', '12345', 'telegram');

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        'api.telegram.org/*' => Http::response(['ok' => true]),
    ]);

    (new CheckGoogleConnections)->handle(
        app(\App\Services\GoogleTokenService::class),
        app(TelegramNotificationService::class),
        app(SettingService::class),
    );

    $connection->refresh();

    expect($connection->is_active)->toBeFalse();
    expect(app(SettingService::class)->get($connection->user_id, "google_connection_alerted_{$connection->id}"))->toBe('1');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.telegram.org');
    });
});

test('job sends the alert only once per broken connection', function () {
    $connection = GaConnection::factory()->expired()->create();

    app(SettingService::class)->set($connection->user_id, 'google_client_id', 'test-client-id', 'google', encrypted: true);
    app(SettingService::class)->set($connection->user_id, 'google_client_secret', 'test-client-secret', 'google', encrypted: true);
    app(SettingService::class)->set($connection->user_id, 'telegram_bot_token', 'test-bot-token', 'telegram', true);
    app(SettingService::class)->set($connection->user_id, 'telegram_chat_id', '12345', 'telegram');
    app(SettingService::class)->set($connection->user_id, "google_connection_alerted_{$connection->id}", '1', 'google');

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        'api.telegram.org/*' => Http::response(['ok' => true]),
    ]);

    (new CheckGoogleConnections)->handle(
        app(\App\Services\GoogleTokenService::class),
        app(TelegramNotificationService::class),
        app(SettingService::class),
    );

    Http::assertSentCount(1);
});

test('job clears the alert flag when the connection becomes healthy again', function () {
    $connection = GaConnection::factory()->expired()->create();

    app(SettingService::class)->set($connection->user_id, 'google_client_id', 'test-client-id', 'google', encrypted: true);
    app(SettingService::class)->set($connection->user_id, 'google_client_secret', 'test-client-secret', 'google', encrypted: true);
    app(SettingService::class)->set($connection->user_id, "google_connection_alerted_{$connection->id}", '1', 'google');

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'fresh-token',
            'expires_in' => 3600,
        ]),
    ]);

    (new CheckGoogleConnections)->handle(
        app(\App\Services\GoogleTokenService::class),
        app(TelegramNotificationService::class),
        app(SettingService::class),
    );

    $connection->refresh();

    expect($connection->is_active)->toBeTrue();
    expect(app(SettingService::class)->get($connection->user_id, "google_connection_alerted_{$connection->id}"))->toBeNull();
});

<?php

use App\Exceptions\GoogleTokenExpiredException;
use App\Models\GaConnection;
use App\Services\GoogleTokenService;
use App\Services\SettingService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $settings = app(SettingService::class);
    $settings->set('google_client_id', 'test-client-id', 'google', encrypted: true);
    $settings->set('google_client_secret', 'test-client-secret', 'google', encrypted: true);
});

test('returns existing token when not expired', function () {
    $connection = GaConnection::factory()->create([
        'token_expires_at' => now()->addHour(),
    ]);

    $service = app(GoogleTokenService::class);
    $token = $service->getFreshToken($connection);

    expect($token)->toBe($connection->access_token);
    Http::assertNothingSent();
});

test('refreshes token when expired', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'new-access-token',
            'expires_in' => 3600,
        ]),
    ]);

    $connection = GaConnection::factory()->expired()->create();

    $service = app(GoogleTokenService::class);
    $token = $service->getFreshToken($connection);

    expect($token)->toBe('new-access-token');

    $connection->refresh();
    expect($connection->access_token)->toBe('new-access-token');
    expect($connection->token_expires_at->isFuture())->toBeTrue();
});

test('deactivates connection when refresh fails', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $connection = GaConnection::factory()->expired()->create();

    $service = app(GoogleTokenService::class);

    expect(fn () => $service->getFreshToken($connection))
        ->toThrow(GoogleTokenExpiredException::class);

    $connection->refresh();
    expect($connection->is_active)->toBeFalse();
});

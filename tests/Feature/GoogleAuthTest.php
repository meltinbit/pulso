<?php

use App\Models\GaConnection;
use App\Models\User;
use App\Services\SettingService;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    $settings = app(SettingService::class);
    $settings->set('google_client_id', 'test-client-id', 'google', encrypted: true);
    $settings->set('google_client_secret', 'test-client-secret', 'google', encrypted: true);
});

test('google redirect requires authentication', function () {
    $response = $this->get('/auth/google');

    $response->assertRedirect('/login');
});

test('google redirect sends user to google', function () {
    $user = User::factory()->create();

    Socialite::fake('google');

    $response = $this
        ->actingAs($user)
        ->get('/auth/google');

    $response->assertRedirect();
});

test('google callback creates a new connection', function () {
    $user = User::factory()->create();

    Socialite::fake('google', (new SocialiteUser)->map([
        'id' => 'google-123',
        'name' => 'Test User',
        'email' => 'test@gmail.com',
    ])->setToken('fake-token')
        ->setRefreshToken('fake-refresh-token')
        ->setExpiresIn(3600));

    $response = $this
        ->actingAs($user)
        ->get('/auth/google/callback');

    $response->assertRedirect(route('settings.google'));

    $connection = GaConnection::where('user_id', $user->id)->first();
    expect($connection)->not->toBeNull();
    expect($connection->google_id)->toBe('google-123');
    expect($connection->google_email)->toBe('test@gmail.com');
    expect($connection->is_active)->toBeTrue();
});

test('google callback updates existing connection', function () {
    $user = User::factory()->create();
    $connection = GaConnection::factory()->for($user)->create([
        'google_id' => 'google-123',
        'google_email' => 'old@gmail.com',
    ]);

    Socialite::fake('google', (new SocialiteUser)->map([
        'id' => 'google-123',
        'name' => 'Updated User',
        'email' => 'new@gmail.com',
    ])->setToken('new-token')
        ->setRefreshToken('new-refresh-token')
        ->setExpiresIn(3600));

    $this
        ->actingAs($user)
        ->get('/auth/google/callback');

    $connection->refresh();
    expect($connection->google_email)->toBe('new@gmail.com');
    expect(GaConnection::where('user_id', $user->id)->count())->toBe(1);
});

test('google disconnect deactivates connection', function () {
    $user = User::factory()->create();
    $connection = GaConnection::factory()->for($user)->create(['is_active' => true]);

    $response = $this
        ->actingAs($user)
        ->delete("/auth/google/{$connection->id}");

    $response->assertRedirect();
    $connection->refresh();
    expect($connection->is_active)->toBeFalse();
});

test('google disconnect prevents disconnecting other users connections', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $connection = GaConnection::factory()->for($otherUser)->create();

    $response = $this
        ->actingAs($user)
        ->delete("/auth/google/{$connection->id}");

    $response->assertForbidden();
});

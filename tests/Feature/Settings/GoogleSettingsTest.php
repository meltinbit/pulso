<?php

use App\Models\AppSetting;
use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Models\User;
use App\Services\SettingService;

test('google settings page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/settings/google');

    $response->assertOk();
});

test('google credentials can be saved', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->put('/settings/google', [
            'google_client_id' => 'test-client-id.apps.googleusercontent.com',
            'google_client_secret' => 'test-client-secret',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $settings = app(SettingService::class);
    expect($settings->get($user->id, 'google_client_id'))->toBe('test-client-id.apps.googleusercontent.com');
    expect($settings->get($user->id, 'google_client_secret'))->toBe('test-client-secret');
});

test('google credentials are stored encrypted', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->put('/settings/google', [
            'google_client_id' => 'test-client-id',
            'google_client_secret' => 'test-secret',
        ]);

    $setting = AppSetting::where('user_id', $user->id)->where('key', 'google_client_secret')->first();
    expect($setting->is_encrypted)->toBeTrue();
    expect($setting->value)->not->toBe('test-secret');
});

test('google settings page shows connections', function () {
    $user = User::factory()->create();
    $connection = GaConnection::factory()->for($user)->create([
        'google_email' => 'test@gmail.com',
    ]);
    GaProperty::factory()->count(2)->for($user)->create(['ga_connection_id' => $connection->id]);

    $response = $this
        ->actingAs($user)
        ->get('/settings/google');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/google')
            ->has('connections', 1)
            ->where('connections.0.google_email', 'test@gmail.com')
            ->where('connections.0.properties_count', 2)
            ->whereNot('connections.0.authorized_at', null)
        );
});

test('google credentials validation requires both fields', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->put('/settings/google', [
            'google_client_id' => '',
            'google_client_secret' => '',
        ]);

    $response->assertSessionHasErrors(['google_client_id', 'google_client_secret']);
});

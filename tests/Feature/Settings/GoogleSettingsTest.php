<?php

use App\Models\AppSetting;
use App\Models\GaConnection;
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
    expect($settings->get('google_client_id'))->toBe('test-client-id.apps.googleusercontent.com');
    expect($settings->get('google_client_secret'))->toBe('test-client-secret');
});

test('google credentials are stored encrypted', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->put('/settings/google', [
            'google_client_id' => 'test-client-id',
            'google_client_secret' => 'test-secret',
        ]);

    $setting = AppSetting::where('key', 'google_client_secret')->first();
    expect($setting->is_encrypted)->toBeTrue();
    expect($setting->value)->not->toBe('test-secret');
});

test('google settings page shows connections', function () {
    $user = User::factory()->create();
    GaConnection::factory()->for($user)->create([
        'google_email' => 'test@gmail.com',
    ]);

    $response = $this
        ->actingAs($user)
        ->get('/settings/google');

    $response->assertOk();
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

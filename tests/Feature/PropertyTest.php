<?php

use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Models\User;
use App\Services\GaPropertyDiscoveryService;
use Illuminate\Support\Facades\Http;

test('properties page requires authentication', function () {
    $response = $this->get('/properties');

    $response->assertRedirect('/login');
});

test('properties page is displayed', function () {
    $user = User::factory()->create();

    $mock = Mockery::mock(GaPropertyDiscoveryService::class);
    $mock->shouldReceive('listAccessibleProperties')->andReturn([]);
    app()->instance(GaPropertyDiscoveryService::class, $mock);

    $response = $this
        ->actingAs($user)
        ->get('/properties');

    $response->assertOk();
});

test('property can be added', function () {
    $user = User::factory()->create();
    $connection = GaConnection::factory()->for($user)->create();

    $response = $this
        ->actingAs($user)
        ->post('/properties', [
            'property_id' => '123456789',
            'ga_connection_id' => $connection->id,
            'display_name' => 'My Website',
            'website_url' => 'https://example.com',
            'timezone' => 'Europe/Rome',
            'currency' => 'EUR',
        ]);

    $response->assertRedirect();

    expect(GaProperty::where('user_id', $user->id)->where('property_id', '123456789')->exists())->toBeTrue();
});

test('duplicate property is not created', function () {
    $user = User::factory()->create();
    $connection = GaConnection::factory()->for($user)->create();
    GaProperty::factory()->for($user)->create([
        'ga_connection_id' => $connection->id,
        'property_id' => '123456789',
    ]);

    $this
        ->actingAs($user)
        ->post('/properties', [
            'property_id' => '123456789',
            'ga_connection_id' => $connection->id,
            'display_name' => 'Another Name',
        ]);

    expect(GaProperty::where('user_id', $user->id)->where('property_id', '123456789')->count())->toBe(1);
});

test('property can be deleted', function () {
    $user = User::factory()->create();
    $property = GaProperty::factory()->for($user)->create();

    $response = $this
        ->actingAs($user)
        ->delete("/properties/{$property->id}");

    $response->assertRedirect();
    expect(GaProperty::find($property->id))->toBeNull();
});

test('cannot delete another users property', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $property = GaProperty::factory()->for($otherUser)->create();

    $response = $this
        ->actingAs($user)
        ->delete("/properties/{$property->id}");

    $response->assertForbidden();
});

test('property can be switched', function () {
    $user = User::factory()->create();
    $property = GaProperty::factory()->for($user)->create();

    $response = $this
        ->actingAs($user)
        ->post('/properties/switch', [
            'property_id' => $property->id,
        ]);

    $response->assertRedirect();
});

test('cannot switch to another users property', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $property = GaProperty::factory()->for($otherUser)->create();

    $response = $this
        ->actingAs($user)
        ->post('/properties/switch', [
            'property_id' => $property->id,
        ]);

    $response->assertSessionHasErrors('property_id');
});

test('property store validates required fields', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post('/properties', []);

    $response->assertSessionHasErrors(['property_id', 'ga_connection_id', 'display_name']);
});

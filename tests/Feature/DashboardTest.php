<?php

use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('guests are redirected to the login page', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('authenticated users can visit the dashboard without properties', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/dashboard')->assertOk();
});

test('dashboard shows overview when property exists', function () {
    Http::fake([
        'analyticsdata.googleapis.com/*' => Http::response([
            'rows' => [
                [
                    'dimensionValues' => [['value' => '20260328']],
                    'metricValues' => [
                        ['value' => '100'],
                        ['value' => '150'],
                        ['value' => '0.45'],
                        ['value' => '120.5'],
                    ],
                ],
            ],
        ]),
    ]);

    $user = User::factory()->create();
    $connection = GaConnection::factory()->for($user)->create();
    GaProperty::factory()->for($user)->create([
        'ga_connection_id' => $connection->id,
    ]);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
});

test('realtime endpoint requires authentication', function () {
    $property = GaProperty::factory()->create();

    $this->get("/api/realtime/{$property->id}")->assertRedirect('/login');
});

test('realtime endpoint returns active users', function () {
    Http::fake([
        'analyticsdata.googleapis.com/*' => Http::response([
            'rows' => [['metricValues' => [['value' => '7']]]],
        ]),
    ]);

    $user = User::factory()->create();
    $connection = GaConnection::factory()->for($user)->create();
    $property = GaProperty::factory()->for($user)->create([
        'ga_connection_id' => $connection->id,
    ]);

    $response = $this->actingAs($user)->get("/api/realtime/{$property->id}");

    $response->assertOk();
    $response->assertJson(['activeUsers' => 7]);
});

test('realtime endpoint blocks other users', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $property = GaProperty::factory()->for($otherUser)->create();

    $response = $this->actingAs($user)->get("/api/realtime/{$property->id}");

    $response->assertForbidden();
});

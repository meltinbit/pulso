<?php

use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('content report requires authentication', function () {
    $this->get('/reports/content')->assertRedirect('/login');
});

test('content report shows empty state without property', function () {
    $this->actingAs(User::factory()->create())
        ->get('/reports/content')
        ->assertOk();
});

test('content report shows data with property', function () {
    Http::fake(['analyticsdata.googleapis.com/*' => Http::response(['rows' => []])]);

    $user = User::factory()->create();
    $connection = GaConnection::factory()->for($user)->create();
    GaProperty::factory()->for($user)->create(['ga_connection_id' => $connection->id]);

    $this->actingAs($user)->get('/reports/content')->assertOk();
});

test('content report accepts period parameter', function () {
    Http::fake(['analyticsdata.googleapis.com/*' => Http::response(['rows' => []])]);

    $user = User::factory()->create();
    $connection = GaConnection::factory()->for($user)->create();
    GaProperty::factory()->for($user)->create(['ga_connection_id' => $connection->id]);

    $this->actingAs($user)->get('/reports/content?period=90d')->assertOk();
});

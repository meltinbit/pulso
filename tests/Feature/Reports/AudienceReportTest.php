<?php

use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('audience report requires authentication', function () {
    $this->get('/reports/audience')->assertRedirect('/login');
});

test('audience report shows empty state without property', function () {
    $this->actingAs(User::factory()->create())
        ->get('/reports/audience')
        ->assertOk();
});

test('audience report shows data with property', function () {
    Http::fake(['analyticsdata.googleapis.com/*' => Http::response(['rows' => []])]);

    $user = User::factory()->create();
    $connection = GaConnection::factory()->for($user)->create();
    GaProperty::factory()->for($user)->create(['ga_connection_id' => $connection->id]);

    $this->actingAs($user)->get('/reports/audience')->assertOk();
});

test('audience report accepts period parameter', function () {
    Http::fake(['analyticsdata.googleapis.com/*' => Http::response(['rows' => []])]);

    $user = User::factory()->create();
    $connection = GaConnection::factory()->for($user)->create();
    GaProperty::factory()->for($user)->create(['ga_connection_id' => $connection->id]);

    $this->actingAs($user)->get('/reports/audience?period=14d')->assertOk();
});

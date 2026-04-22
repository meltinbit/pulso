<?php

use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

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

test('dashboard events hide noise, compute deltas, and build phrases', function () {
    $eventRow = fn (string $name, int $count, int $users) => [
        'dimensionValues' => [['value' => $name]],
        'metricValues' => [['value' => (string) $count], ['value' => (string) $users]],
    ];

    Http::fake([
        'analyticsdata.googleapis.com/*' => Http::sequence()
            ->push(['rows' => []]) // overview
            ->push(['rows' => [['metricValues' => [['value' => '0']]], ['metricValues' => [['value' => '0']]]]]) // today
            ->push(['rows' => []]) // countries
            ->push(['rows' => []]) // devices
            ->push(['rows' => []]) // pages
            ->push(['rows' => []]) // channels
            ->push(['rows' => [ // events current
                $eventRow('page_view', 1000, 200),       // noise — hidden
                $eventRow('session_start', 300, 200),    // noise — hidden
                $eventRow('user_engagement', 400, 150),  // noise — hidden
                $eventRow('scroll', 205, 82),
                $eventRow('calcolo_eseguito', 200, 67),
                $eventRow('feedback_calcolatore', 5, 1),
            ]])
            ->push(['rows' => [ // events previous
                $eventRow('scroll', 150, 60),
                $eventRow('calcolo_eseguito', 40, 20),
                // feedback_calcolatore absent → baseline 0 → delta null → "new"
            ]])
            ->push(['rows' => [['metricValues' => [['value' => '0']]]]]), // realtime
    ]);

    $user = User::factory()->create();
    $connection = GaConnection::factory()->for($user)->create();
    GaProperty::factory()->for($user)->create(['ga_connection_id' => $connection->id]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('dashboard/overview')
            ->has('events', 3) // noise filtered out
            ->where('events.0.name', 'scroll')
            ->where('events.0.phrase', 'read a page to the end')
            ->where('events.0.users', 82)
            ->where('events.0.delta_users_pct', 36.7) // (82-60)/60 = 36.67
            ->where('events.1.name', 'calcolo_eseguito')
            ->where('events.1.is_custom', true)
            ->where('events.1.phrase', null)
            ->where('events.1.users', 67)
            ->where('events.1.delta_users_pct', 235) // (67-20)/20
            ->where('events.2.name', 'feedback_calcolatore')
            ->where('events.2.delta_users_pct', null) // no baseline
            ->where('events.2.users_previous', 0)
        );
});

test('realtime endpoint blocks other users', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $property = GaProperty::factory()->for($otherUser)->create();

    $response = $this->actingAs($user)->get("/api/realtime/{$property->id}");

    $response->assertForbidden();
});

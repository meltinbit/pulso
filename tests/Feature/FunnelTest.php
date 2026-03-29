<?php

use App\Models\Funnel;
use App\Models\FunnelStep;
use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('funnels index requires authentication', function () {
    $this->get('/funnels')->assertRedirect('/login');
});

test('funnels index shows empty state without property', function () {
    $this->actingAs(User::factory()->create())
        ->get('/funnels')
        ->assertOk();
});

test('funnels index shows list', function () {
    $user = User::factory()->create();
    $connection = GaConnection::factory()->for($user)->create();
    $property = GaProperty::factory()->for($user)->create(['ga_connection_id' => $connection->id]);

    session(['active_property_id' => $property->id]);

    $funnel = Funnel::factory()->create([
        'user_id' => $user->id,
        'ga_property_id' => $property->id,
    ]);
    FunnelStep::factory()->create(['funnel_id' => $funnel->id, 'order' => 1]);
    FunnelStep::factory()->create(['funnel_id' => $funnel->id, 'order' => 2]);

    $this->actingAs($user)->get('/funnels')->assertOk();
});

test('funnel create page is accessible', function () {
    $this->actingAs(User::factory()->create())
        ->get('/funnels/create')
        ->assertOk();
});

test('funnel can be created with steps', function () {
    $user = User::factory()->create();
    $connection = GaConnection::factory()->for($user)->create();
    $property = GaProperty::factory()->for($user)->create(['ga_connection_id' => $connection->id]);

    session(['active_property_id' => $property->id]);

    $response = $this->actingAs($user)->post('/funnels', [
        'name' => 'Onboarding',
        'description' => 'User onboarding flow',
        'is_open' => false,
        'steps' => [
            ['name' => 'First open', 'event_name' => 'first_open'],
            ['name' => 'Sign up', 'event_name' => 'sign_up'],
            ['name' => 'Purchase', 'event_name' => 'purchase'],
        ],
    ]);

    $response->assertRedirect();

    $funnel = Funnel::where('user_id', $user->id)->first();
    expect($funnel)->not->toBeNull();
    expect($funnel->name)->toBe('Onboarding');
    expect($funnel->steps()->count())->toBe(3);
    expect($funnel->steps()->orderBy('order')->first()->event_name)->toBe('first_open');
});

test('funnel requires at least 2 steps', function () {
    $user = User::factory()->create();
    $connection = GaConnection::factory()->for($user)->create();
    $property = GaProperty::factory()->for($user)->create(['ga_connection_id' => $connection->id]);

    session(['active_property_id' => $property->id]);

    $response = $this->actingAs($user)->post('/funnels', [
        'name' => 'Bad funnel',
        'steps' => [
            ['name' => 'Only one', 'event_name' => 'page_view'],
        ],
    ]);

    $response->assertSessionHasErrors('steps');
});

test('funnel show calls v1alpha and displays results', function () {
    Http::fake([
        'analyticsdata.googleapis.com/v1alpha/*' => Http::response([
            'funnelVisualization' => [
                'rows' => [
                    [
                        'dimensionValues' => [['value' => 'First open']],
                        'metricValues' => [['value' => '100'], ['value' => '0'], ['value' => '0']],
                    ],
                    [
                        'dimensionValues' => [['value' => 'Sign up']],
                        'metricValues' => [['value' => '60'], ['value' => '40'], ['value' => '0.4']],
                    ],
                ],
            ],
        ]),
    ]);

    $user = User::factory()->create();
    $connection = GaConnection::factory()->for($user)->create();
    $property = GaProperty::factory()->for($user)->create(['ga_connection_id' => $connection->id]);

    $funnel = Funnel::factory()->create([
        'user_id' => $user->id,
        'ga_property_id' => $property->id,
    ]);
    FunnelStep::factory()->create(['funnel_id' => $funnel->id, 'order' => 1, 'name' => 'First open', 'event_name' => 'first_open']);
    FunnelStep::factory()->create(['funnel_id' => $funnel->id, 'order' => 2, 'name' => 'Sign up', 'event_name' => 'sign_up']);

    $this->actingAs($user)->get("/funnels/{$funnel->id}")->assertOk();
});

test('funnel can be deleted by owner', function () {
    $user = User::factory()->create();
    $funnel = Funnel::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->delete("/funnels/{$funnel->id}")
        ->assertRedirect(route('funnels.index'));

    expect(Funnel::find($funnel->id))->toBeNull();
});

test('funnel cannot be viewed by other users', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    Http::fake(['analyticsdata.googleapis.com/*' => Http::response(['funnelVisualization' => ['rows' => []]])]);

    $funnel = Funnel::factory()->create(['user_id' => $otherUser->id]);

    $this->actingAs($user)
        ->get("/funnels/{$funnel->id}")
        ->assertForbidden();
});

test('funnel cannot be deleted by other users', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $funnel = Funnel::factory()->create(['user_id' => $otherUser->id]);

    $this->actingAs($user)
        ->delete("/funnels/{$funnel->id}")
        ->assertForbidden();
});

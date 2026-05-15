<?php

use App\Mcp\Servers\PulsoServer;
use App\Mcp\Tools\GetPropertyEventsTool;
use App\Mcp\Tools\GetPropertyIndexStatusTool;
use App\Mcp\Tools\GetPropertyPagesTool;
use App\Mcp\Tools\GetPropertySearchQueriesTool;
use App\Mcp\Tools\GetPropertySnapshotsTool;
use App\Mcp\Tools\GetPropertySourcesTool;
use App\Mcp\Tools\GetPropertySummaryTool;
use App\Mcp\Tools\ListPropertiesTool;
use App\Models\GaProperty;
use App\Models\PropertySnapshot;
use App\Models\PropertySnapshotEvent;
use App\Models\PropertySnapshotPage;
use App\Models\PropertySnapshotSearchQuery;
use App\Models\PropertySnapshotSource;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;

it('lists active properties with latest snapshot', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $property = GaProperty::factory()->for($user)->create(['display_name' => 'Test Site']);
    PropertySnapshot::factory()->for($property, 'gaProperty')->create([
        'snapshot_date' => now()->subDay(),
        'trend' => 'improved',
        'trend_score' => 25.5,
    ]);

    GaProperty::factory()->create(['display_name' => 'Other Site']);

    $response = PulsoServer::tool(ListPropertiesTool::class, []);

    $response->assertOk();
    $response->assertSee('Test Site');
    $response->assertSee('improved');
    $response->assertDontSee('Other Site');
});

it('returns snapshots for a property within date range', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $property = GaProperty::factory()->for($user)->create();

    PropertySnapshot::factory()->for($property, 'gaProperty')->create([
        'snapshot_date' => now()->subDays(2),
        'users' => 100,
    ]);
    PropertySnapshot::factory()->for($property, 'gaProperty')->create([
        'snapshot_date' => now()->subDays(1),
        'users' => 150,
    ]);
    PropertySnapshot::factory()->for($property, 'gaProperty')->create([
        'snapshot_date' => now()->subDays(40),
        'users' => 50,
    ]);

    $response = PulsoServer::tool(GetPropertySnapshotsTool::class, [
        'property_id' => $property->id,
        'from' => now()->subDays(7)->toDateString(),
        'to' => now()->toDateString(),
    ]);

    $response->assertOk();
    $response->assertSee('"count": 2');
});

it('validates property_id is required for snapshots', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = PulsoServer::tool(GetPropertySnapshotsTool::class, []);

    $response->assertHasErrors();
});

it('returns aggregated traffic sources', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $property = GaProperty::factory()->for($user)->create();
    $snapshot = PropertySnapshot::factory()->for($property, 'gaProperty')->create([
        'snapshot_date' => now()->subDay(),
    ]);

    PropertySnapshotSource::factory()->for($snapshot, 'snapshot')->create([
        'source' => 'google',
        'medium' => 'organic',
        'sessions' => 500,
        'users' => 400,
    ]);
    PropertySnapshotSource::factory()->for($snapshot, 'snapshot')->create([
        'source' => 'google',
        'medium' => 'organic',
        'sessions' => 300,
        'users' => 200,
    ]);
    PropertySnapshotSource::factory()->for($snapshot, 'snapshot')->create([
        'source' => 'direct',
        'medium' => '(none)',
        'sessions' => 100,
        'users' => 80,
    ]);

    $response = PulsoServer::tool(GetPropertySourcesTool::class, [
        'property_id' => $property->id,
    ]);

    $response->assertOk();
    $response->assertSee('google');
    $response->assertSee('"total_sessions": 800');
});

it('returns property summary with averages and anomalies', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $property = GaProperty::factory()->for($user)->create(['display_name' => 'Summary Site']);

    PropertySnapshot::factory()->for($property, 'gaProperty')->create([
        'snapshot_date' => now()->subDays(3),
        'users' => 100,
        'sessions' => 120,
        'pageviews' => 300,
        'bounce_rate' => 45.0,
        'trend' => 'stall',
        'is_spike' => false,
        'is_drop' => false,
    ]);

    $latest = PropertySnapshot::factory()->for($property, 'gaProperty')->spike()->create([
        'snapshot_date' => now()->subDay(),
        'users' => 500,
        'sessions' => 600,
        'pageviews' => 1500,
        'bounce_rate' => 30.0,
    ]);

    PropertySnapshotSource::factory()->for($latest, 'snapshot')->create([
        'source' => 'google',
        'medium' => 'organic',
        'sessions' => 400,
    ]);

    $response = PulsoServer::tool(GetPropertySummaryTool::class, [
        'property_id' => $property->id,
    ]);

    $response->assertOk();
    $response->assertSee('Summary Site');
    $response->assertSee('spike');
    $response->assertSee('"users": 500');
});

it('aggregates events across date range and filters by event name', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $property = GaProperty::factory()->for($user)->create(['display_name' => 'Events Site']);

    $snapshotA = PropertySnapshot::factory()->for($property, 'gaProperty')->create([
        'snapshot_date' => now()->subDays(2),
    ]);
    $snapshotB = PropertySnapshot::factory()->for($property, 'gaProperty')->create([
        'snapshot_date' => now()->subDays(1),
    ]);
    PropertySnapshot::factory()->for($property, 'gaProperty')->create([
        'snapshot_date' => now()->subDays(40),
    ])->events()->create([
        'event_name' => 'calcolo_eseguito',
        'event_count' => 9999,
        'total_users' => 9999,
    ]);

    PropertySnapshotEvent::factory()->for($snapshotA, 'snapshot')->create([
        'event_name' => 'calcolo_eseguito',
        'event_count' => 40,
        'total_users' => 30,
    ]);
    PropertySnapshotEvent::factory()->for($snapshotB, 'snapshot')->create([
        'event_name' => 'calcolo_eseguito',
        'event_count' => 60,
        'total_users' => 50,
    ]);
    PropertySnapshotEvent::factory()->for($snapshotB, 'snapshot')->create([
        'event_name' => 'page_view',
        'event_count' => 1000,
        'total_users' => 500,
    ]);

    $response = PulsoServer::tool(GetPropertyEventsTool::class, [
        'property_id' => $property->id,
        'from' => now()->subDays(7)->toDateString(),
        'to' => now()->toDateString(),
        'event_name' => 'calcolo_eseguito',
    ]);

    $response->assertOk();
    $response->assertSee('Events Site');
    $response->assertSee('calcolo_eseguito');
    $response->assertSee('"total_event_count": 100');
    $response->assertSee('"total_users": 80');
});

it('validates property_id is required for events', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = PulsoServer::tool(GetPropertyEventsTool::class, []);

    $response->assertHasErrors();
});

it('returns message when property has no snapshots', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $property = GaProperty::factory()->for($user)->create();

    $response = PulsoServer::tool(GetPropertySummaryTool::class, [
        'property_id' => $property->id,
    ]);

    $response->assertOk();
    $response->assertSee('No snapshots available');
});

it('returns index status inspection results for provided urls', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $property = GaProperty::factory()->for($user)->create([
        'display_name' => 'Index Site',
        'website_url' => 'https://example.com',
    ]);

    Http::fake([
        'searchconsole.googleapis.com/webmasters/v3/sites' => Http::response([
            'siteEntry' => [['siteUrl' => 'sc-domain:example.com']],
        ]),
        'searchconsole.googleapis.com/v1/urlInspection/index:inspect' => Http::sequence()
            ->push([
                'inspectionResult' => [
                    'indexStatusResult' => [
                        'verdict' => 'PASS',
                        'coverageState' => 'Submitted and indexed',
                    ],
                ],
            ])
            ->push([
                'inspectionResult' => [
                    'indexStatusResult' => [
                        'verdict' => 'NEUTRAL',
                        'coverageState' => 'Excluded by noindex tag',
                    ],
                ],
            ]),
    ]);

    $response = PulsoServer::tool(GetPropertyIndexStatusTool::class, [
        'property_id' => $property->id,
        'urls' => [
            'https://example.com/indexed',
            'https://example.com/excluded',
        ],
    ]);

    $response->assertOk();
    $response->assertSee('Index Site');
    $response->assertSee('"indexed_count": 1');
    $response->assertSee('"not_indexed_count": 1');
    $response->assertSee('Excluded by noindex tag');
});

it('returns pages for the authenticated users property', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $property = GaProperty::factory()->for($user)->create(['display_name' => 'Pages Site']);
    $snapshot = PropertySnapshot::factory()->for($property, 'gaProperty')->create([
        'snapshot_date' => now()->subDay(),
    ]);

    PropertySnapshotPage::factory()->for($snapshot, 'snapshot')->create([
        'page_path' => '/landing',
        'page_title' => 'Landing',
        'pageviews' => 250,
        'users' => 120,
    ]);

    $response = PulsoServer::tool(GetPropertyPagesTool::class, [
        'property_id' => $property->id,
    ]);

    $response->assertOk();
    $response->assertSee('Landing');
});

it('returns search queries for the authenticated users property', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $property = GaProperty::factory()->for($user)->create(['display_name' => 'Queries Site']);
    $snapshot = PropertySnapshot::factory()->for($property, 'gaProperty')->create([
        'snapshot_date' => now()->subDay(),
    ]);

    PropertySnapshotSearchQuery::factory()->for($snapshot, 'snapshot')->create([
        'query' => 'pulso analytics',
        'page' => '/analytics',
        'clicks' => 42,
        'impressions' => 400,
    ]);

    $response = PulsoServer::tool(GetPropertySearchQueriesTool::class, [
        'property_id' => $property->id,
    ]);

    $response->assertOk();
    $response->assertSee('pulso analytics');
});

it('cannot access another users property through mcp tools', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $this->actingAs($user);

    $property = GaProperty::factory()->for($otherUser)->create();

    expect(fn () => PulsoServer::tool(GetPropertySummaryTool::class, [
        'property_id' => $property->id,
    ]))->toThrow(ModelNotFoundException::class);
});

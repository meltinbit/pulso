<?php

use App\Mcp\Servers\PulsoServer;
use App\Mcp\Tools\GetPropertySnapshotsTool;
use App\Mcp\Tools\GetPropertySourcesTool;
use App\Mcp\Tools\GetPropertySummaryTool;
use App\Mcp\Tools\ListPropertiesTool;
use App\Models\GaProperty;
use App\Models\PropertySnapshot;
use App\Models\PropertySnapshotSource;

it('lists active properties with latest snapshot', function () {
    $property = GaProperty::factory()->create(['display_name' => 'Test Site']);
    PropertySnapshot::factory()->for($property, 'gaProperty')->create([
        'snapshot_date' => now()->subDay(),
        'trend' => 'improved',
        'trend_score' => 25.5,
    ]);

    GaProperty::factory()->create(['is_active' => false]);

    $response = PulsoServer::tool(ListPropertiesTool::class, []);

    $response->assertOk();
    $response->assertSee('Test Site');
    $response->assertSee('improved');
});

it('returns snapshots for a property within date range', function () {
    $property = GaProperty::factory()->create();

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
    $response = PulsoServer::tool(GetPropertySnapshotsTool::class, []);

    $response->assertHasErrors();
});

it('returns aggregated traffic sources', function () {
    $property = GaProperty::factory()->create();
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
    $property = GaProperty::factory()->create(['display_name' => 'Summary Site']);

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

it('returns message when property has no snapshots', function () {
    $property = GaProperty::factory()->create();

    $response = PulsoServer::tool(GetPropertySummaryTool::class, [
        'property_id' => $property->id,
    ]);

    $response->assertOk();
    $response->assertSee('No snapshots available');
});

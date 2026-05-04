<?php

use App\Console\Commands\GenerateSnapshotsCommand;
use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Models\PropertySnapshot;
use App\Models\User;
use App\Services\SnapshotAnalyzerService;
use App\Services\TelegramNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(Carbon::parse('2026-04-24 09:00:00'));
});

function fakeGaApiResponses(): void
{
    Http::fake([
        'analyticsdata.googleapis.com/*' => Http::sequence()
            // Day 1 (call for each day x 7 endpoints)
            ->push(['rows' => [['metricValues' => [['value' => '500'], ['value' => '700'], ['value' => '2000'], ['value' => '45.5'], ['value' => '120']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '400'], ['value' => '560'], ['value' => '1800'], ['value' => '47.5'], ['value' => '110']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '450'], ['value' => '595']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => 'google'], ['value' => 'organic']], 'metricValues' => [['value' => '300'], ['value' => '250']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '450'], ['value' => '0.6428']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => '/'], ['value' => 'Home']], 'metricValues' => [['value' => '800'], ['value' => '600'], ['value' => '0.35'], ['value' => '95'], ['value' => '0.65']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => 'page_view']], 'metricValues' => [['value' => '2000'], ['value' => '500']]]]])
            // Day 2
            ->push(['rows' => [['metricValues' => [['value' => '510'], ['value' => '710'], ['value' => '2010'], ['value' => '45.6'], ['value' => '121']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '410'], ['value' => '570'], ['value' => '1810'], ['value' => '47.6'], ['value' => '111']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '460'], ['value' => '605']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => 'google'], ['value' => 'organic']], 'metricValues' => [['value' => '310'], ['value' => '260']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '460'], ['value' => '0.6429']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => '/'], ['value' => 'Home']], 'metricValues' => [['value' => '810'], ['value' => '610'], ['value' => '0.36'], ['value' => '96'], ['value' => '0.66']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => 'page_view']], 'metricValues' => [['value' => '2010'], ['value' => '510']]]]])
            // Add enough sequences for the tests
            ->dontFailWhenEmpty(),
        'searchconsole.googleapis.com/*' => Http::response(['rows' => []]),
        'api.telegram.org/*' => Http::response(['ok' => true]),
    ]);
}

test('snapshots:generate command with auto-backfill fills in missing snapshots between last and current date', function () {
    // Create a property
    $user = User::factory()->create();
    $connection = GaConnection::factory()->for($user)->active()->create();
    $property = GaProperty::factory()->active()->for($user)->for($connection, 'gaConnection')->create();
    
    // Create a snapshot from 3 days ago
    $oldSnapshot = PropertySnapshot::factory()->for($property, 'gaProperty')->create([
        'snapshot_date' => Carbon::parse('2026-04-21')->startOfDay(), // 3 days ago
    ]);
    
    // Setup HTTP fakes
    fakeGaApiResponses();
    
    $this->artisan('snapshots:generate --auto-backfill')
         ->assertSuccessful();
    
    // Should have created snapshots for Apr 22 and Apr 23 (yesterday)
    expect(PropertySnapshot::count())->toBe(3);
    
    // Verify we have snapshots for the right days
    expect(PropertySnapshot::where('snapshot_date', Carbon::parse('2026-04-21')->startOfDay())->exists())->toBeTrue();
    expect(PropertySnapshot::where('snapshot_date', Carbon::parse('2026-04-22')->startOfDay())->exists())->toBeTrue();
    expect(PropertySnapshot::where('snapshot_date', Carbon::parse('2026-04-23')->startOfDay())->exists())->toBeTrue();
});

test('snapshots:generate command with auto-backfill creates last 30 days if no snapshots exist', function () {
    // Create a property
    $user = User::factory()->create();
    $connection = GaConnection::factory()->for($user)->active()->create();
    $property = GaProperty::factory()->active()->for($user)->for($connection, 'gaConnection')->create();
    
    // No pre-existing snapshot
    
    // Setup HTTP fakes - we need a lot of responses for 30 days
    // This is a simplified version that will allow all requests
    Http::fake([
        'analyticsdata.googleapis.com/*' => Http::response([
            'rows' => [
                [
                    'metricValues' => [
                        ['value' => '500'],
                        ['value' => '700'],
                        ['value' => '2000'],
                        ['value' => '45.5'],
                        ['value' => '120']
                    ]
                ]
            ]
        ]),
        'searchconsole.googleapis.com/*' => Http::response(['rows' => []]),
        'api.telegram.org/*' => Http::response(['ok' => true]),
    ]);
    
    $this->artisan('snapshots:generate --auto-backfill --property='.$property->id)
         ->assertSuccessful();
    
    // Should have created snapshots for the last 30 days
    expect(PropertySnapshot::count())->toBeGreaterThanOrEqual(30);
    
    // Verify we have a snapshot for yesterday
    expect(PropertySnapshot::where('snapshot_date', Carbon::yesterday()->startOfDay())->exists())->toBeTrue();
});

test('snapshots:generate command with auto-backfill respects --property filter', function () {
    // Create two properties
    $user = User::factory()->create();
    $connection = GaConnection::factory()->for($user)->active()->create();
    
    $property1 = GaProperty::factory()->active()->for($user)->for($connection, 'gaConnection')->create();
    $property2 = GaProperty::factory()->active()->for($user)->for($connection, 'gaConnection')->create();
    
    // Create snapshots for both properties 3 days ago
    PropertySnapshot::factory()->for($property1, 'gaProperty')->create([
        'snapshot_date' => Carbon::parse('2026-04-21')->startOfDay(), // 3 days ago
    ]);
    
    PropertySnapshot::factory()->for($property2, 'gaProperty')->create([
        'snapshot_date' => Carbon::parse('2026-04-21')->startOfDay(), // 3 days ago
    ]);
    
    // Setup HTTP fakes
    fakeGaApiResponses();
    
    // Run command only for property1
    $this->artisan('snapshots:generate --auto-backfill --property='.$property1->id)
         ->assertSuccessful();
    
    // Should have created 2 new snapshots for property1 only
    expect(PropertySnapshot::count())->toBe(4); // 2 original + 2 for property1
    
    // Verify property1 has 3 snapshots total
    expect(PropertySnapshot::where('ga_property_id', $property1->id)->count())->toBe(3);
    
    // Verify property2 still has just 1 snapshot
    expect(PropertySnapshot::where('ga_property_id', $property2->id)->count())->toBe(1);
});

test('snapshots:generate command with auto-backfill respects --no-telegram option', function () {
    // Create a property
    $user = User::factory()->create();
    $connection = GaConnection::factory()->for($user)->active()->create();
    $property = GaProperty::factory()->active()->for($user)->for($connection, 'gaConnection')->create();
    
    // Create a snapshot from 3 days ago
    $oldSnapshot = PropertySnapshot::factory()->for($property, 'gaProperty')->create([
        'snapshot_date' => Carbon::parse('2026-04-21')->startOfDay(), // 3 days ago
    ]);
    
    // Setup HTTP fakes
    fakeGaApiResponses();
    
    $this->artisan('snapshots:generate --auto-backfill --no-telegram')
         ->assertSuccessful();
    
    // Should have created snapshots for Apr 22 and Apr 23 (yesterday)
    expect(PropertySnapshot::count())->toBe(3);
    
    // Verify Telegram API was NOT called
    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), 'api.telegram.org');
    });
});

test('snapshots:generate command with auto-backfill handles API errors gracefully', function () {
    // Create a property
    $user = User::factory()->create();
    $connection = GaConnection::factory()->for($user)->active()->create();
    $property = GaProperty::factory()->active()->for($user)->for($connection, 'gaConnection')->create();
    
    // Create a snapshot from 3 days ago
    $oldSnapshot = PropertySnapshot::factory()->for($property, 'gaProperty')->create([
        'snapshot_date' => Carbon::parse('2026-04-21')->startOfDay(), // 3 days ago
    ]);
    
    // Setup HTTP fakes to fail
    Http::fake([
        'analyticsdata.googleapis.com/*' => Http::response(['error' => ['message' => 'API quota exceeded']], 429),
    ]);
    
    Log::shouldReceive('warning')->atLeast(1);
    
    $this->artisan('snapshots:generate --auto-backfill')
         ->assertFailed();
    
    // Should still have only the original snapshot
    expect(PropertySnapshot::count())->toBe(1);
});
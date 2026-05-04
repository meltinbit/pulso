<?php

use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Models\PropertySnapshot;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(Carbon::parse('2026-04-24'));
});

test('generate creates a snapshot for yesterday when no previous snapshots exist', function () {
    $user = User::factory()->create();
    $connection = GaConnection::factory()->for($user)->create();
    $property = GaProperty::factory()->for($user)->for($connection, 'gaConnection')->create();
    
    Http::fake([
        'analyticsdata.googleapis.com/*' => Http::sequence()
            ->push(['rows' => [['metricValues' => [['value' => '500'], ['value' => '700'], ['value' => '2000'], ['value' => '45.5'], ['value' => '120']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '400'], ['value' => '560'], ['value' => '1800'], ['value' => '47.5'], ['value' => '110']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '450'], ['value' => '595']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => 'google'], ['value' => 'organic']], 'metricValues' => [['value' => '300'], ['value' => '250']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '450'], ['value' => '0.6428']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => '/'], ['value' => 'Home']], 'metricValues' => [['value' => '800'], ['value' => '600'], ['value' => '0.35'], ['value' => '95'], ['value' => '0.65']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => 'page_view']], 'metricValues' => [['value' => '2000'], ['value' => '500']]]]])
            ->dontFailWhenEmpty(),
        'searchconsole.googleapis.com/*' => Http::response(['rows' => []]),
    ]);
    
    $response = actingAs($user)->post(route('snapshots.generate'));
    
    $response->assertRedirect();
    $response->assertSessionHas('success');
    
    // We should now have 1 snapshot
    expect(PropertySnapshot::count())->toBe(1);
    
    // Check that the snapshot is created for yesterday
    expect(PropertySnapshot::first()->snapshot_date->toDateString())->toBe('2026-04-23');
});

test('generate fills missing snapshots between last snapshot and yesterday', function () {
    $user = User::factory()->create();
    $connection = GaConnection::factory()->for($user)->create();
    $property = GaProperty::factory()->for($user)->for($connection, 'gaConnection')->create();
    
    // Create a snapshot for 3 days ago (April 21st)
    $oldSnapshot = PropertySnapshot::factory()->for($property, 'gaProperty')->create([
        'snapshot_date' => Carbon::parse('2026-04-21')->startOfDay(),
        'users' => 100,
        'sessions' => 200,
        'pageviews' => 500,
    ]);
    
    // Setup HTTP fakes (will need 2 days of snapshots = 14 calls, 7 per day)
    Http::fake([
        'analyticsdata.googleapis.com/*' => Http::sequence()
            // April 22
            ->push(['rows' => [['metricValues' => [['value' => '510'], ['value' => '710'], ['value' => '2010'], ['value' => '45.5'], ['value' => '121']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '401'], ['value' => '561'], ['value' => '1801'], ['value' => '47.5'], ['value' => '111']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '451'], ['value' => '596']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => 'google'], ['value' => 'organic']], 'metricValues' => [['value' => '301'], ['value' => '251']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '451'], ['value' => '0.6429']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => '/'], ['value' => 'Home']], 'metricValues' => [['value' => '801'], ['value' => '601'], ['value' => '0.35'], ['value' => '95'], ['value' => '0.65']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => 'page_view']], 'metricValues' => [['value' => '2001'], ['value' => '501']]]]])
            // April 23 (yesterday)
            ->push(['rows' => [['metricValues' => [['value' => '520'], ['value' => '720'], ['value' => '2020'], ['value' => '45.6'], ['value' => '122']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '402'], ['value' => '562'], ['value' => '1802'], ['value' => '47.6'], ['value' => '112']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '452'], ['value' => '597']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => 'google'], ['value' => 'organic']], 'metricValues' => [['value' => '302'], ['value' => '252']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '452'], ['value' => '0.6430']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => '/'], ['value' => 'Home']], 'metricValues' => [['value' => '802'], ['value' => '602'], ['value' => '0.35'], ['value' => '95'], ['value' => '0.65']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => 'page_view']], 'metricValues' => [['value' => '2002'], ['value' => '502']]]]])
            ->dontFailWhenEmpty(),
        'searchconsole.googleapis.com/*' => Http::response(['rows' => []]),
    ]);
    
    $response = actingAs($user)->post(route('snapshots.generate'));
    
    $response->assertRedirect();
    $response->assertSessionHas('success', 'Generati 2 snapshots per recuperare i dati mancanti.');
    
    // We should now have 3 snapshots total (the original + 2 filled in)
    expect(PropertySnapshot::count())->toBe(3);
    
    // Verify we have snapshots for Apr 21, 22, and 23
    expect(PropertySnapshot::where('snapshot_date', Carbon::parse('2026-04-21')->startOfDay())->exists())->toBeTrue();
    expect(PropertySnapshot::where('snapshot_date', Carbon::parse('2026-04-22')->startOfDay())->exists())->toBeTrue();
    expect(PropertySnapshot::where('snapshot_date', Carbon::parse('2026-04-23')->startOfDay())->exists())->toBeTrue();
});

test('generate updates existing snapshot if one already exists for yesterday', function () {
    $user = User::factory()->create();
    $connection = GaConnection::factory()->for($user)->create();
    $property = GaProperty::factory()->for($user)->for($connection, 'gaConnection')->create();
    
    // Create a snapshot for yesterday
    $existingSnapshot = PropertySnapshot::factory()->for($property, 'gaProperty')->create([
        'snapshot_date' => Carbon::yesterday()->startOfDay(),
        'users' => 100,
        'sessions' => 200,
        'pageviews' => 500,
    ]);
    
    // Setup HTTP fakes
    Http::fake([
        'analyticsdata.googleapis.com/*' => Http::sequence()
            ->push(['rows' => [['metricValues' => [['value' => '520'], ['value' => '720'], ['value' => '2020'], ['value' => '45.6'], ['value' => '122']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '402'], ['value' => '562'], ['value' => '1802'], ['value' => '47.6'], ['value' => '112']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '452'], ['value' => '597']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => 'google'], ['value' => 'organic']], 'metricValues' => [['value' => '302'], ['value' => '252']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '452'], ['value' => '0.6430']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => '/'], ['value' => 'Home']], 'metricValues' => [['value' => '802'], ['value' => '602'], ['value' => '0.35'], ['value' => '95'], ['value' => '0.65']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => 'page_view']], 'metricValues' => [['value' => '2002'], ['value' => '502']]]]])
            ->dontFailWhenEmpty(),
        'searchconsole.googleapis.com/*' => Http::response(['rows' => []]),
    ]);
    
    $response = actingAs($user)->post(route('snapshots.generate'));
    
    $response->assertRedirect();
    $response->assertSessionHas('success');
    
    // We should still have only 1 snapshot
    expect(PropertySnapshot::count())->toBe(1);
    
    // Verify the snapshot has been updated with new data
    $updatedSnapshot = PropertySnapshot::first();
    expect($updatedSnapshot->id)->toBe($existingSnapshot->id);
    expect($updatedSnapshot->users)->not->toBe(100);
    expect($updatedSnapshot->users)->toBe(520);
});

test('generate handles API error gracefully', function () {
    $user = User::factory()->create();
    $connection = GaConnection::factory()->for($user)->create();
    $property = GaProperty::factory()->for($user)->for($connection, 'gaConnection')->create();
    
    Http::fake([
        'analyticsdata.googleapis.com/*' => Http::response(['error' => ['message' => 'API quota exceeded']], 429),
    ]);
    
    $response = actingAs($user)->post(route('snapshots.generate'));
    
    $response->assertRedirect();
    $response->assertSessionHas('error');
    
    expect(PropertySnapshot::count())->toBe(0);
});
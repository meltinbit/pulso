<?php

use App\Jobs\GenerateDailySnapshots;
use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Models\PropertySnapshot;
use App\Services\SettingService;
use App\Services\SnapshotAnalyzerService;
use App\Services\TelegramNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow(Carbon::parse('2026-04-24 09:00:00', 'UTC'));
});

function fakeAllGaAndTelegram(): void
{
    Http::fake([
        'analyticsdata.googleapis.com/*' => Http::sequence()
            ->push(['rows' => [['metricValues' => [['value' => '500'], ['value' => '700'], ['value' => '2000'], ['value' => '45.5'], ['value' => '120']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '400'], ['value' => '560'], ['value' => '1800'], ['value' => '47.5'], ['value' => '110']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '450'], ['value' => '595']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => 'google'], ['value' => 'organic']], 'metricValues' => [['value' => '300'], ['value' => '250']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '450'], ['value' => '0.6428']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => '/'], ['value' => 'Home']], 'metricValues' => [['value' => '800'], ['value' => '600'], ['value' => '0.35'], ['value' => '95'], ['value' => '0.65']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => 'page_view']], 'metricValues' => [['value' => '2000'], ['value' => '500']]]]]),
        'searchconsole.googleapis.com/*' => Http::response(['rows' => []]),
        'api.telegram.org/*' => Http::response(['ok' => true]),
    ]);
}

test('job creates snapshots for all active properties', function () {
    $connection = GaConnection::factory()->create();
    GaProperty::factory()->create([
        'user_id' => $connection->user_id,
        'ga_connection_id' => $connection->id,
        'is_active' => true,
    ]);

    app(SettingService::class)->set($connection->user_id, 'telegram_bot_token', 'test-token', 'telegram', true);
    app(SettingService::class)->set($connection->user_id, 'telegram_chat_id', '12345', 'telegram');

    fakeAllGaAndTelegram();

    (new GenerateDailySnapshots)->handle(
        app(SnapshotAnalyzerService::class),
        app(TelegramNotificationService::class),
        app(SettingService::class),
    );

    expect(PropertySnapshot::count())->toBe(1);
});

test('job skips inactive properties', function () {
    $connection = GaConnection::factory()->create();
    GaProperty::factory()->create([
        'user_id' => $connection->user_id,
        'ga_connection_id' => $connection->id,
        'is_active' => false,
    ]);

    (new GenerateDailySnapshots)->handle(
        app(SnapshotAnalyzerService::class),
        app(TelegramNotificationService::class),
        app(SettingService::class),
    );

    expect(PropertySnapshot::count())->toBe(0);
});

test('job skips properties with inactive connections', function () {
    $connection = GaConnection::factory()->inactive()->create();
    GaProperty::factory()->create([
        'user_id' => $connection->user_id,
        'ga_connection_id' => $connection->id,
        'is_active' => true,
    ]);

    (new GenerateDailySnapshots)->handle(
        app(SnapshotAnalyzerService::class),
        app(TelegramNotificationService::class),
        app(SettingService::class),
    );

    expect(PropertySnapshot::count())->toBe(0);
});

test('job continues processing after individual property failure', function () {
    $connection = GaConnection::factory()->create();
    $property1 = GaProperty::factory()->create([
        'user_id' => $connection->user_id,
        'ga_connection_id' => $connection->id,
        'property_id' => '111111111',
        'is_active' => true,
    ]);
    $property2 = GaProperty::factory()->create([
        'user_id' => $connection->user_id,
        'ga_connection_id' => $connection->id,
        'property_id' => '222222222',
        'is_active' => true,
    ]);

    Http::fake([
        'analyticsdata.googleapis.com/*' => Http::sequence()
            // Property 1: fails
            ->push(['error' => 'quota exceeded'], 429)
            // Property 2: succeeds (7 calls)
            ->push(['rows' => [['metricValues' => [['value' => '500'], ['value' => '700'], ['value' => '2000'], ['value' => '45.5'], ['value' => '120']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '400'], ['value' => '560'], ['value' => '1800'], ['value' => '47.5'], ['value' => '110']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '450'], ['value' => '595']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => 'google'], ['value' => 'organic']], 'metricValues' => [['value' => '300'], ['value' => '250']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '450'], ['value' => '0.6428']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => '/'], ['value' => 'Home']], 'metricValues' => [['value' => '800'], ['value' => '600'], ['value' => '0.35'], ['value' => '95'], ['value' => '0.65']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => 'page_view']], 'metricValues' => [['value' => '2000'], ['value' => '500']]]]]),
        'searchconsole.googleapis.com/*' => Http::response(['rows' => []]),
        'api.telegram.org/*' => Http::response(['ok' => true]),
    ]);

    app(SettingService::class)->set($connection->user_id, 'telegram_bot_token', 'test-token', 'telegram', true);
    app(SettingService::class)->set($connection->user_id, 'telegram_chat_id', '12345', 'telegram');

    Log::shouldReceive('warning')->once();
    Log::shouldReceive('info')->once();

    (new GenerateDailySnapshots)->handle(
        app(SnapshotAnalyzerService::class),
        app(TelegramNotificationService::class),
        app(SettingService::class),
    );

    // Only property 2 should have a snapshot
    expect(PropertySnapshot::count())->toBe(1);
});

test('job sends Telegram digest after generating snapshots', function () {
    $connection = GaConnection::factory()->create();
    GaProperty::factory()->create([
        'user_id' => $connection->user_id,
        'ga_connection_id' => $connection->id,
        'is_active' => true,
    ]);

    app(SettingService::class)->set($connection->user_id, 'telegram_bot_token', 'test-token', 'telegram', true);
    app(SettingService::class)->set($connection->user_id, 'telegram_chat_id', '12345', 'telegram');

    fakeAllGaAndTelegram();

    (new GenerateDailySnapshots)->handle(
        app(SnapshotAnalyzerService::class),
        app(TelegramNotificationService::class),
        app(SettingService::class),
    );

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.telegram.org');
});

test('job detects and fills in missing snapshots between last snapshot and yesterday', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-24 09:00:00', 'UTC')); // Today is April 24th

    $connection = GaConnection::factory()->create();
    $property = GaProperty::factory()->create([
        'user_id' => $connection->user_id,
        'ga_connection_id' => $connection->id,
        'is_active' => true,
    ]);
    
    // Create a snapshot for 3 days ago (April 21st)
    $oldSnapshot = PropertySnapshot::factory()->for($property, 'gaProperty')->create([
        'snapshot_date' => Carbon::parse('2026-04-21')->startOfDay(),
        'users' => 100,
        'sessions' => 200,
        'pageviews' => 500,
    ]);
    
    // Setup HTTP fakes (will need 2 days of snapshots = 14 calls, 7 per day)
    // Each day needs 7 API calls: core metrics, week ago metrics, avg metrics, sources, engagement, pages, events
    Http::fake([
        'analyticsdata.googleapis.com/*' => Http::sequence()
            // April 22 (14 total calls - 7 per property)
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
        'api.telegram.org/*' => Http::response(['ok' => true]),
    ]);

    app(SettingService::class)->set($connection->user_id, 'telegram_bot_token', 'test-token', 'telegram', true);
    app(SettingService::class)->set($connection->user_id, 'telegram_chat_id', '12345', 'telegram');

    // We expect 2 log messages for backfill snapshots and 1 for the Telegram message
    Log::spy();
    
    (new GenerateDailySnapshots)->handle(
        app(SnapshotAnalyzerService::class),
        app(TelegramNotificationService::class),
        app(SettingService::class),
    );
    
    // We should now have 3 snapshots total (the original + 2 filled in)
    expect(PropertySnapshot::count())->toBe(3);
    
    // Verify we have snapshots for Apr 21, 22, and 23
    expect(PropertySnapshot::where('snapshot_date', Carbon::parse('2026-04-21')->startOfDay())->exists())->toBeTrue();
    expect(PropertySnapshot::where('snapshot_date', Carbon::parse('2026-04-22')->startOfDay())->exists())->toBeTrue();
    expect(PropertySnapshot::where('snapshot_date', Carbon::parse('2026-04-23')->startOfDay())->exists())->toBeTrue();
    
    // Verify the Telegram functionality
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.telegram.org');
    });
});

});

test('job does nothing when no users have active properties', function () {
    (new GenerateDailySnapshots)->handle(
        app(SnapshotAnalyzerService::class),
        app(TelegramNotificationService::class),
        app(SettingService::class),
    );

    expect(PropertySnapshot::count())->toBe(0);
});

test('job runs for user whose snapshot_time hour matches current UTC hour', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-24 05:00:00', 'UTC'));

    $connection = GaConnection::factory()->create();
    GaProperty::factory()->create([
        'user_id' => $connection->user_id,
        'ga_connection_id' => $connection->id,
        'is_active' => true,
    ]);

    app(SettingService::class)->set($connection->user_id, 'snapshot_time', '05:00', 'snapshots');

    fakeAllGaAndTelegram();

    (new GenerateDailySnapshots)->handle(
        app(SnapshotAnalyzerService::class),
        app(TelegramNotificationService::class),
        app(SettingService::class),
    );

    expect(PropertySnapshot::count())->toBe(1);
});

test('job skips user whose snapshot_time hour does not match current UTC hour', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-24 09:00:00', 'UTC'));

    $connection = GaConnection::factory()->create();
    GaProperty::factory()->create([
        'user_id' => $connection->user_id,
        'ga_connection_id' => $connection->id,
        'is_active' => true,
    ]);

    app(SettingService::class)->set($connection->user_id, 'snapshot_time', '05:00', 'snapshots');

    (new GenerateDailySnapshots)->handle(
        app(SnapshotAnalyzerService::class),
        app(TelegramNotificationService::class),
        app(SettingService::class),
    );

    expect(PropertySnapshot::count())->toBe(0);
});

test('job ignores minutes and matches only on hour', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-24 05:00:00', 'UTC'));

    $connection = GaConnection::factory()->create();
    GaProperty::factory()->create([
        'user_id' => $connection->user_id,
        'ga_connection_id' => $connection->id,
        'is_active' => true,
    ]);

    app(SettingService::class)->set($connection->user_id, 'snapshot_time', '05:30', 'snapshots');

    fakeAllGaAndTelegram();

    (new GenerateDailySnapshots)->handle(
        app(SnapshotAnalyzerService::class),
        app(TelegramNotificationService::class),
        app(SettingService::class),
    );

    expect(PropertySnapshot::count())->toBe(1);
});

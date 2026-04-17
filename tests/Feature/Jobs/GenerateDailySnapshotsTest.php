<?php

use App\Jobs\GenerateDailySnapshots;
use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Models\PropertySnapshot;
use App\Services\SettingService;
use App\Services\SnapshotAnalyzerService;
use App\Services\TelegramNotificationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Http::preventStrayRequests();
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
            ->push(['rows' => [['dimensionValues' => [['value' => '/'], ['value' => 'Home']], 'metricValues' => [['value' => '800'], ['value' => '600'], ['value' => '0.35'], ['value' => '95'], ['value' => '0.65']]]]]),
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
            // Property 2: succeeds (6 calls)
            ->push(['rows' => [['metricValues' => [['value' => '500'], ['value' => '700'], ['value' => '2000'], ['value' => '45.5'], ['value' => '120']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '400'], ['value' => '560'], ['value' => '1800'], ['value' => '47.5'], ['value' => '110']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '450'], ['value' => '595']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => 'google'], ['value' => 'organic']], 'metricValues' => [['value' => '300'], ['value' => '250']]]]])
            ->push(['rows' => [['metricValues' => [['value' => '450'], ['value' => '0.6428']]]]])
            ->push(['rows' => [['dimensionValues' => [['value' => '/'], ['value' => 'Home']], 'metricValues' => [['value' => '800'], ['value' => '600'], ['value' => '0.35'], ['value' => '95'], ['value' => '0.65']]]]]),
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
});

test('job does nothing when no users have active properties', function () {
    (new GenerateDailySnapshots)->handle(
        app(SnapshotAnalyzerService::class),
        app(TelegramNotificationService::class),
        app(SettingService::class),
    );

    expect(PropertySnapshot::count())->toBe(0);
});

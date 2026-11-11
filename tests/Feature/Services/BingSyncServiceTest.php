<?php

use App\Jobs\SyncBingWebmasterData;
use App\Models\GaProperty;
use App\Models\PropertyBingCrawlStat;
use App\Models\PropertyBingSearchStat;
use App\Models\PropertyBingTraffic;
use App\Models\User;
use App\Services\BingSyncService;
use App\Services\BingWebmasterService;
use App\Services\SettingService;
use Illuminate\Support\Facades\Http;

/** Milliseconds at UTC midnight, as Bing sends them. */
function bingDate(string $date): string
{
    return '/Date('.(strtotime($date.' 00:00:00 UTC') * 1000).')/';
}

function fakeBing(array $overrides = []): void
{
    Http::fake($overrides + [
        'ssl.bing.com/webmaster/api.svc/json/GetUserSites*' => Http::response(['d' => [
            ['Url' => 'https://www.example.com/', 'IsVerified' => true],
            ['Url' => 'https://unverified.test/', 'IsVerified' => false],
        ]]),
        'ssl.bing.com/webmaster/api.svc/json/GetRankAndTrafficStats*' => Http::response(['d' => [
            ['Date' => bingDate('2026-11-05'), 'Clicks' => 10, 'Impressions' => 200],
            ['Date' => bingDate('2026-11-06'), 'Clicks' => 12, 'Impressions' => 220],
        ]]),
        'ssl.bing.com/webmaster/api.svc/json/GetQueryStats*' => Http::response(['d' => [
            ['Date' => bingDate('2026-11-06'), 'Query' => 'calcolo imu', 'Clicks' => 5, 'Impressions' => 80, 'AvgImpressionPosition' => 4.26, 'AvgClickPosition' => -1],
        ]]),
        'ssl.bing.com/webmaster/api.svc/json/GetPageStats*' => Http::response(['d' => [
            ['Date' => bingDate('2026-11-06'), 'Query' => 'https://www.example.com/imu/', 'Clicks' => 7, 'Impressions' => 90, 'AvgImpressionPosition' => 3.1, 'AvgClickPosition' => 2.5],
        ]]),
        'ssl.bing.com/webmaster/api.svc/json/GetCrawlStats*' => Http::response(['d' => [
            ['Date' => bingDate('2026-11-06'), 'CrawledPages' => 40, 'InIndex' => 120, 'Code4xx' => 2, 'CrawlErrors' => 1],
        ]]),
    ]);
}

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    app(SettingService::class)->set($this->user->id, BingSyncService::SETTING_KEY, 'test-key', 'bing', encrypted: true);
});

it('parses bing dates and maps missing positions to null', function () {
    $service = app(BingWebmasterService::class);

    expect($service->parseDate('/Date(1753401600000)/'))->toBe('2025-07-25')
        ->and($service->parseDate('/Date(1753401600000-0700)/'))->toBe('2025-07-25');

    fakeBing();
    $row = $service->queryStats('test-key', 'https://www.example.com/')[0];

    expect($row['week_start'])->toBe('2026-10-30')
        ->and($row['avg_click_position'])->toBeNull()
        ->and($row['avg_impression_position'])->toBe(4.3);
});

it('links properties to verified bing sites by host', function (string $websiteUrl, bool $linked) {
    fakeBing();
    $property = GaProperty::factory()->for($this->user)->create(['website_url' => $websiteUrl]);

    $sites = app(BingSyncService::class)->matchSites($this->user, 'test-key');

    expect($property->fresh()->bing_site_url)->toBe($linked ? 'https://www.example.com/' : null)
        ->and($sites[0]['property_id'])->toBe($linked ? $property->id : null);
})->with([
    'same host' => ['https://www.example.com', true],
    'without www' => ['https://example.com/', true],
    'other host' => ['https://other.test', false],
    'unverified site' => ['https://unverified.test', false],
]);

it('stores bing data idempotently', function () {
    fakeBing();
    $property = GaProperty::factory()->for($this->user)->create(['bing_site_url' => 'https://www.example.com/']);
    $sync = app(BingSyncService::class);

    $sync->sync($property, 'test-key');
    $counts = $sync->sync($property, 'test-key');

    expect($counts)->toBe(['traffic' => 2, 'queries' => 1, 'pages' => 1, 'crawl' => 1])
        ->and(PropertyBingTraffic::count())->toBe(2)
        ->and(PropertyBingSearchStat::where('dimension', 'query')->count())->toBe(1)
        ->and(PropertyBingSearchStat::where('dimension', 'page')->value('value'))->toBe('https://www.example.com/imu/')
        ->and(PropertyBingCrawlStat::sole()->in_index)->toBe(120)
        ->and(PropertyBingCrawlStat::sole()->code_4xx)->toBe(2);
});

it('handles sites without data', function () {
    Http::fake(['ssl.bing.com/*' => Http::response(['d' => []])]);
    $property = GaProperty::factory()->for($this->user)->create(['bing_site_url' => 'https://www.example.com/']);

    expect(app(BingSyncService::class)->sync($property, 'test-key'))
        ->toBe(['traffic' => 0, 'queries' => 0, 'pages' => 0, 'crawl' => 0]);
});

it('syncs linked properties and alerts once on telegram when the key is invalid', function () {
    $settings = app(SettingService::class);
    $settings->set($this->user->id, 'telegram_bot_token', 'tg', 'telegram', true);
    $settings->set($this->user->id, 'telegram_chat_id', '1', 'telegram');

    Http::fake([
        'ssl.bing.com/*' => Http::response(['ErrorCode' => 3, 'Message' => 'ERROR!!! InvalidApiKey'], 400),
        'api.telegram.org/*' => Http::response(['ok' => true]),
    ]);

    dispatch_sync(new SyncBingWebmasterData);
    dispatch_sync(new SyncBingWebmasterData);

    Http::assertSentCount(3);
    expect($settings->get($this->user->id, BingSyncService::ALERTED_SETTING_KEY))->toBe('1');
});

it('downloads data for linked properties in the daily job', function () {
    fakeBing();
    GaProperty::factory()->for($this->user)->create(['website_url' => 'https://example.com']);

    $this->artisan('pulso:sync-bing')->assertSuccessful();

    expect(PropertyBingTraffic::count())->toBe(2);
});

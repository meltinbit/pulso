<?php

use App\Exceptions\SearchConsoleApiException;
use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Models\PropertySearchQuery;
use App\Services\SearchConsoleSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Cache::flush();
    Sleep::fake();
    Carbon::setTestNow('2026-05-20 08:00:00');

    $connection = GaConnection::factory()->create([
        'access_token' => 'fake-token',
        'token_expires_at' => now()->addHour(),
    ]);

    $this->property = GaProperty::factory()
        ->for($connection, 'gaConnection')
        ->create(['website_url' => 'https://example.com']);
});

/**
 * @param  array<int, array{0: string, 1: string, 2: string, 3: int, 4: int}>  $rows
 * @return array<string, mixed>
 */
function searchAnalyticsResponse(array $rows): array
{
    return ['rows' => array_map(fn (array $row): array => [
        'keys' => [$row[0], $row[1], $row[2]],
        'clicks' => $row[3],
        'impressions' => $row[4],
        'ctr' => $row[4] > 0 ? $row[3] / $row[4] : 0,
        'position' => 3.0,
    ], $rows)];
}

test('stores rows keyed by their own day so ranges sum', function () {
    Http::fake([
        'searchconsole.googleapis.com/webmasters/v3/sites' => Http::response([
            'siteEntry' => [['siteUrl' => 'sc-domain:example.com']],
        ]),
        'searchconsole.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response(
            searchAnalyticsResponse([
                ['2026-05-16', 'calcolatore imu', 'https://example.com/imu', 10, 100],
                ['2026-05-17', 'calcolatore imu', 'https://example.com/imu', 4, 60],
                ['2026-05-17', 'calcolo tasi', 'https://example.com/tasi', 2, 40],
            ])
        ),
    ]);

    $stored = app(SearchConsoleSyncService::class)->syncRange(
        $this->property,
        Carbon::parse('2026-05-16'),
        Carbon::parse('2026-05-17'),
    );

    expect($stored)->toBe(3);
    expect(PropertySearchQuery::where('date', '2026-05-16')->count())->toBe(1);
    expect(PropertySearchQuery::where('date', '2026-05-17')->count())->toBe(2);

    $totalClicks = PropertySearchQuery::where('query', 'calcolatore imu')->sum('clicks');
    expect((int) $totalClicks)->toBe(14);
});

test('re-syncing a day replaces its rows instead of duplicating them', function () {
    Http::fake([
        'searchconsole.googleapis.com/webmasters/v3/sites' => Http::response([
            'siteEntry' => [['siteUrl' => 'sc-domain:example.com']],
        ]),
        'searchconsole.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::sequence()
            ->push(searchAnalyticsResponse([
                ['2026-05-17', 'calcolatore imu', 'https://example.com/imu', 4, 60],
            ]))
            // Search Console consolidated the day: more clicks, one more query.
            ->push(searchAnalyticsResponse([
                ['2026-05-17', 'calcolatore imu', 'https://example.com/imu', 9, 120],
                ['2026-05-17', 'calcolo tasi', 'https://example.com/tasi', 2, 40],
            ])),
    ]);

    $service = app(SearchConsoleSyncService::class);
    $day = Carbon::parse('2026-05-17');

    $service->syncRange($this->property, $day, $day);
    $service->syncRange($this->property, $day, $day);

    expect(PropertySearchQuery::where('date', '2026-05-17')->count())->toBe(2);
    expect((int) PropertySearchQuery::where('query', 'calcolatore imu')->sum('clicks'))->toBe(9);
});

test('syncRecent re-downloads the last four days up to yesterday', function () {
    Http::fake([
        'searchconsole.googleapis.com/webmasters/v3/sites' => Http::response([
            'siteEntry' => [['siteUrl' => 'sc-domain:example.com']],
        ]),
        'searchconsole.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response(['rows' => []]),
    ]);

    app(SearchConsoleSyncService::class)->syncRecent($this->property);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'searchAnalytics/query')
        && $request['startDate'] === '2026-05-16'
        && $request['endDate'] === '2026-05-19');
});

test('backfill covers the sixteen months Google retains, one window per call', function () {
    Http::fake([
        'searchconsole.googleapis.com/webmasters/v3/sites' => Http::response([
            'siteEntry' => [['siteUrl' => 'sc-domain:example.com']],
        ]),
        'searchconsole.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response(['rows' => []]),
    ]);

    app(SearchConsoleSyncService::class)->backfill($this->property);

    $queryRequests = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn ($request) => str_contains($request->url(), 'searchAnalytics/query'));

    expect($queryRequests->first()['startDate'])->toBe('2025-01-20');
    expect($queryRequests->last()['endDate'])->toBe('2026-05-19');
    expect($queryRequests->count())->toBe(70);
});

test('an empty day clears rows that were stored for it before', function () {
    PropertySearchQuery::factory()->for($this->property, 'gaProperty')->create([
        'date' => '2026-05-17',
        'query' => 'stale query',
    ]);

    Http::fake([
        'searchconsole.googleapis.com/webmasters/v3/sites' => Http::response([
            'siteEntry' => [['siteUrl' => 'sc-domain:example.com']],
        ]),
        'searchconsole.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response(['rows' => []]),
    ]);

    $day = Carbon::parse('2026-05-17');
    app(SearchConsoleSyncService::class)->syncRange($this->property, $day, $day);

    expect(PropertySearchQuery::where('date', '2026-05-17')->count())->toBe(0);
});

test('a failing api call leaves the already stored rows untouched', function () {
    PropertySearchQuery::factory()->for($this->property, 'gaProperty')->create([
        'date' => '2026-05-17',
        'query' => 'already stored',
    ]);

    Http::fake([
        'searchconsole.googleapis.com/webmasters/v3/sites' => Http::response([
            'siteEntry' => [['siteUrl' => 'sc-domain:example.com']],
        ]),
        'searchconsole.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response(['error' => 'forbidden'], 403),
    ]);

    $day = Carbon::parse('2026-05-17');

    expect(fn () => app(SearchConsoleSyncService::class)->syncRange($this->property, $day, $day))
        ->toThrow(SearchConsoleApiException::class);

    expect(PropertySearchQuery::where('date', '2026-05-17')->count())->toBe(1);
});

<?php

use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Models\PropertySearchQuery;
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
        ->create(['website_url' => 'https://example.com', 'display_name' => 'Example']);

    Http::fake([
        'searchconsole.googleapis.com/webmasters/v3/sites' => Http::response([
            'siteEntry' => [['siteUrl' => 'sc-domain:example.com']],
        ]),
    ]);
});

function fakeSearchAnalytics(mixed $response): void
{
    Http::fake(['searchconsole.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => $response]);
}

function fakeSearchAnalyticsRows(): void
{
    fakeSearchAnalytics(Http::response([
        'rows' => [[
            'keys' => ['2026-05-19', 'calcolatore imu', 'https://example.com/imu'],
            'clicks' => 10, 'impressions' => 100, 'ctr' => 0.1, 'position' => 3.2,
        ]],
    ]));
}

it('syncs the recent window by default', function () {
    fakeSearchAnalyticsRows();

    $this->artisan('search-console:sync')
        ->expectsOutputToContain('Example')
        ->assertSuccessful();

    expect(PropertySearchQuery::where('ga_property_id', $this->property->id)->count())->toBe(1);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'searchAnalytics/query')
        && $request['startDate'] === '2026-05-16');
});

it('backfills the full retention window', function () {
    fakeSearchAnalyticsRows();

    $this->artisan('search-console:sync', ['--backfill' => true, '--property' => $this->property->id])
        ->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'searchAnalytics/query')
        && $request['startDate'] === '2025-01-20');
});

it('syncs an explicit date range', function () {
    fakeSearchAnalyticsRows();

    $this->artisan('search-console:sync', ['--from' => '2026-05-01', '--to' => '2026-05-03'])
        ->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'searchAnalytics/query')
        && $request['startDate'] === '2026-05-01'
        && $request['endDate'] === '2026-05-03');
});

it('reports a failure when the api rejects the request', function () {
    fakeSearchAnalytics(Http::response(['error' => 'forbidden'], 403));

    $this->artisan('search-console:sync')->assertFailed();

    expect(PropertySearchQuery::count())->toBe(0);
});

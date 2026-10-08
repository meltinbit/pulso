<?php

use App\Exceptions\AdSenseApiException;
use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Models\PropertyAdsenseMetric;
use App\Services\AdSenseSyncService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Cache::flush();
    Sleep::fake();
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-05-20 08:00:00');

    $connection = GaConnection::factory()->create([
        'access_token' => 'fake-token',
        'token_expires_at' => now()->addHour(),
        'scopes' => 'analytics.readonly webmasters.readonly adsense.readonly',
    ]);

    $this->property = GaProperty::factory()
        ->for($connection, 'gaConnection')
        ->create(['website_url' => 'https://www.example.com', 'currency' => 'EUR']);
});

/**
 * Build an AdSense report response for the requested breakdown dimension.
 *
 * @param  array<string, array<int, array{0: string, 1: string, 2: float, 3: int}>>  $rowsByDimension  dimension => [date, value, earnings, page views]
 */
function fakeAdSenseReports(array $rowsByDimension): void
{
    Http::fake([
        'adsense.googleapis.com/v2/accounts' => Http::response([
            'accounts' => [['name' => 'accounts/pub-123', 'displayName' => 'Test', 'state' => 'READY']],
        ]),
        'adsense.googleapis.com/v2/accounts/pub-123/reports:generate*' => function (Request $request) use ($rowsByDimension) {
            preg_match_all('/dimensions=([A-Z_]+)/', $request->url(), $matches);
            $dimension = $matches[1][1] ?? 'TOTAL';
            $headers = array_merge(['DATE'], $dimension === 'TOTAL' ? [] : [$dimension], ['ESTIMATED_EARNINGS', 'PAGE_VIEWS', 'IMPRESSIONS', 'CLICKS', 'AD_REQUESTS', 'MATCHED_AD_REQUESTS']);

            return Http::response([
                'headers' => array_map(fn (string $name): array => ['name' => $name], $headers),
                'rows' => array_map(fn (array $row): array => ['cells' => array_map(
                    fn ($value): array => ['value' => (string) $value],
                    array_merge([$row[0]], $dimension === 'TOTAL' ? [] : [$row[1]], [$row[2], $row[3], $row[3] * 2, 3, $row[3] * 3, $row[3] * 2]),
                )], $rowsByDimension[$dimension] ?? []),
            ]);
        },
    ]);
}

test('stores day totals and breakdowns filtered to the property domain', function () {
    fakeAdSenseReports([
        'TOTAL' => [['2026-05-18', '', 4.5, 1000], ['2026-05-19', '', 2.25, 600]],
        'COUNTRY_NAME' => [['2026-05-18', 'Italy', 4.0, 900], ['2026-05-18', 'Germany', 0.5, 100]],
        'PLATFORM_TYPE_NAME' => [['2026-05-19', 'Mobile', 2.25, 600]],
    ]);

    $stored = app(AdSenseSyncService::class)->syncRange($this->property, Carbon::parse('2026-05-18'), Carbon::parse('2026-05-19'));

    expect($stored)->toBe(2);
    expect(PropertyAdsenseMetric::where('dimension', 'total')->count())->toBe(2);
    expect(PropertyAdsenseMetric::where('dimension', 'country')->pluck('dimension_value')->sort()->values()->all())->toBe(['Germany', 'Italy']);
    expect((float) PropertyAdsenseMetric::where('dimension', 'total')->sum('earnings'))->toBe(6.75);

    $total = PropertyAdsenseMetric::where('dimension', 'total')->where('date', '2026-05-18')->first();
    expect($total->page_views)->toBe(1000)
        ->and($total->clicks)->toBe(3)
        ->and($total->matched_ad_requests)->toBe(2000);

    Http::assertSent(function (Request $request) {
        $url = urldecode($request->url());

        return str_contains($url, 'reports:generate')
            && str_contains($url, 'filters=DOMAIN_NAME==example.com,DOMAIN_NAME==www.example.com')
            && str_contains($url, 'metrics=ESTIMATED_EARNINGS&metrics=PAGE_VIEWS')
            && str_contains($url, 'startDate.year=2026&startDate.month=5&startDate.day=18')
            && str_contains($url, 'currencyCode=EUR')
            && $request->hasHeader('Authorization', 'Bearer fake-token');
    });
});

test('re-syncing a day replaces its rows instead of duplicating them', function () {
    PropertyAdsenseMetric::factory()->for($this->property, 'gaProperty')->create(['date' => '2026-05-19', 'earnings' => 1]);
    PropertyAdsenseMetric::factory()->for($this->property, 'gaProperty')->breakdown('country', 'France')->create(['date' => '2026-05-19']);

    fakeAdSenseReports([
        'TOTAL' => [['2026-05-19', '', 3.0, 700]],
        'COUNTRY_NAME' => [['2026-05-19', 'Italy', 3.0, 700]],
    ]);

    app(AdSenseSyncService::class)->syncRange($this->property, Carbon::parse('2026-05-19'), Carbon::parse('2026-05-19'));

    expect(PropertyAdsenseMetric::where('dimension', 'total')->count())->toBe(1);
    expect((float) PropertyAdsenseMetric::where('dimension', 'total')->value('earnings'))->toBe(3.0);
    expect(PropertyAdsenseMetric::where('dimension', 'country')->pluck('dimension_value')->all())->toBe(['Italy']);
});

test('sync recent re-downloads the last days up to today', function () {
    fakeAdSenseReports(['TOTAL' => [['2026-05-20', '', 1.0, 100]]]);

    app(AdSenseSyncService::class)->syncRecent($this->property);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'startDate.day=14')
        && str_contains($request->url(), 'endDate.day=20'));
    expect(PropertyAdsenseMetric::where('date', '2026-05-20')->exists())->toBeTrue();
});

test('throws when the google account has no adsense account', function () {
    Http::fake(['adsense.googleapis.com/v2/accounts' => Http::response(['accounts' => []])]);

    app(AdSenseSyncService::class)->syncRecent($this->property);
})->throws(AdSenseApiException::class);

test('throws when the report api fails', function () {
    Http::fake([
        'adsense.googleapis.com/v2/accounts' => Http::response(['accounts' => [['name' => 'accounts/pub-123', 'state' => 'READY']]]),
        'adsense.googleapis.com/v2/accounts/pub-123/reports:generate*' => Http::response(['error' => 'denied'], 403),
    ]);

    app(AdSenseSyncService::class)->syncRecent($this->property);
})->throws(AdSenseApiException::class);

<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Configure a full set of Google Ads credentials for the test.
 */
function configureGoogleAds(array $overrides = []): void
{
    config(['services.google_ads' => array_merge(config('services.google_ads'), [
        'developer_token' => 'dev-token',
        'login_customer_id' => '111-222-3333',
        'customer_id' => '444-555-6666',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'refresh_token' => 'refresh-token',
        'daily_operations_limit' => 100,
    ], $overrides)]);
}

/**
 * A Google Ads keyword result in the REST shape (int64 values as strings).
 *
 * @return array<string, mixed>
 */
function googleAdsKeywordMetrics(int $volume = 1300, string $competition = 'HIGH'): array
{
    $months = ['OCTOBER', 'NOVEMBER', 'DECEMBER', 'JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE', 'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER'];

    return [
        'competition' => $competition,
        'avgMonthlySearches' => (string) $volume,
        'competitionIndex' => '73',
        'lowTopOfPageBidMicros' => '120000',
        'highTopOfPageBidMicros' => '1456789',
        'monthlySearchVolumes' => array_map(fn (string $month, int $i): array => [
            'month' => $month,
            'year' => $i < 3 ? '2025' : '2026',
            'monthlySearches' => (string) ($i < 3 ? 1000 : ($i >= 10 ? 1500 : 1300)),
        ], $months, array_keys($months)),
    ];
}

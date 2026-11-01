<?php

use App\Exceptions\GoogleAdsApiException;
use App\Models\GoogleAdsDailyUsage;
use App\Models\KeywordResearchCache;
use App\Services\GoogleAdsClient;
use App\Services\GoogleAdsKeywordService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Cache::flush();
    Sleep::fake();
    Http::preventStrayRequests();
    configureGoogleAds();
});

function fakeGoogleAdsIdeas(): void
{
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ads-token', 'expires_in' => 3599]),
        'googleads.googleapis.com/v26/customers/4445556666:generateKeywordIdeas' => Http::response([
            'results' => [
                ['text' => 'calcolo imu', 'keywordIdeaMetrics' => googleAdsKeywordMetrics(1300)],
                ['text' => 'aliquote imu', 'keywordIdeaMetrics' => ['competition' => 'UNSPECIFIED']],
            ],
        ]),
    ]);
}

test('maps keyword ideas from the REST response', function () {
    fakeGoogleAdsIdeas();

    $ideas = app(GoogleAdsKeywordService::class)->keywordIdeas(['Calcolo IMU '], null, [], null, 50);

    expect($ideas)->toHaveCount(2);
    expect($ideas[0])->toMatchArray([
        'keyword' => 'calcolo imu',
        'volume' => 1300,
        'competition' => 'HIGH',
        'competition_index' => 73,
        'cpc_low' => 0.12,
        'cpc_high' => 1.46,
        'trend' => 50.0,
    ]);
    expect($ideas[0]['monthly'])->toHaveCount(13);
    expect($ideas[0]['monthly'][0])->toBe(['month' => '2025-10', 'searches' => 1000]);
    expect($ideas[0]['monthly'][12])->toBe(['month' => '2026-10', 'searches' => 1500]);

    expect($ideas[1])->toMatchArray([
        'volume' => null,
        'competition' => null,
        'cpc_low' => null,
        'trend' => null,
        'monthly' => [],
    ]);
});

test('sends seeds, Italian language, Italy and Google Search network with Ads headers', function () {
    fakeGoogleAdsIdeas();

    app(GoogleAdsKeywordService::class)->keywordIdeas(['calcolo imu'], null, [], null, 50);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'generateKeywordIdeas')) {
            return false;
        }

        return $request->hasHeader('developer-token', 'dev-token')
            && $request->hasHeader('login-customer-id', '1112223333')
            && $request->hasHeader('Authorization', 'Bearer ads-token')
            && $request['language'] === 'languageConstants/1004'
            && $request['geoTargetConstants'] === ['geoTargetConstants/2380']
            && $request['keywordPlanNetwork'] === 'GOOGLE_SEARCH'
            && $request['keywordSeed'] === ['keywords' => ['calcolo imu']]
            && $request['pageSize'] === 50
            && $request['historicalMetricsOptions'] === ['yearMonthRange' => [
                'start' => ['year' => now()->subMonths(24)->year, 'month' => strtoupper(now()->startOfMonth()->subMonths(24)->englishMonth)],
                'end' => ['year' => now()->year, 'month' => strtoupper(now()->englishMonth)],
            ]];
    });
});

test('uses a keyword and url seed when both are given', function () {
    fakeGoogleAdsIdeas();

    app(GoogleAdsKeywordService::class)->keywordIdeas(['imu'], 'https://example.com/imu', ['1008463'], 'en');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'generateKeywordIdeas')
        && $request['keywordAndUrlSeed'] === ['url' => 'https://example.com/imu', 'keywords' => ['imu']]
        && $request['geoTargetConstants'] === ['geoTargetConstants/1008463']
        && $request['language'] === 'languageConstants/1000');
});

test('serves repeated requests from the database cache without spending operations', function () {
    fakeGoogleAdsIdeas();
    $service = app(GoogleAdsKeywordService::class);

    $first = $service->keywordIdeas(['calcolo imu']);
    $second = $service->keywordIdeas([' CALCOLO  imu']);

    expect($second)->toEqual($first);
    expect(KeywordResearchCache::count())->toBe(1);
    expect(KeywordResearchCache::first()->expires_at->isSameDay(now()->addDays(30)))->toBeTrue();
    expect(app(GoogleAdsClient::class)->operationsToday())->toBe(1);
    Http::assertSentCount(2);
});

test('calls the API again once the cache has expired', function () {
    fakeGoogleAdsIdeas();
    $service = app(GoogleAdsKeywordService::class);

    $service->keywordIdeas(['calcolo imu']);
    $this->travel(31)->days();
    Cache::flush();
    $service->keywordIdeas(['calcolo imu']);

    Http::assertSentCount(4);
});

test('refuses to call the API once the daily limit is reached', function () {
    fakeGoogleAdsIdeas();
    GoogleAdsDailyUsage::create(['date' => now()->toDateString(), 'operations' => 100]);

    expect(fn () => app(GoogleAdsKeywordService::class)->keywordIdeas(['calcolo imu']))
        ->toThrow(GoogleAdsApiException::class, 'Limite giornaliero di 100 operazioni');

    Http::assertNothingSent();
});

test('reports missing credentials without calling Google', function () {
    configureGoogleAds(['developer_token' => null, 'refresh_token' => '']);

    expect(fn () => app(GoogleAdsKeywordService::class)->keywordIdeas(['imu']))
        ->toThrow(GoogleAdsApiException::class, 'mancano GOOGLE_ADS_DEVELOPER_TOKEN, GOOGLE_ADS_REFRESH_TOKEN');

    Http::assertNothingSent();
});

test('translates Google Ads errors into readable messages', function (int $status, array $errorCode, string $expected) {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ads-token', 'expires_in' => 3599]),
        'googleads.googleapis.com/*' => Http::response([
            'error' => [
                'code' => $status,
                'message' => 'The caller does not have permission',
                'status' => 'PERMISSION_DENIED',
                'details' => [[
                    '@type' => 'type.googleapis.com/google.ads.googleads.v26.errors.GoogleAdsFailure',
                    'errors' => [['errorCode' => $errorCode, 'message' => 'raw google message']],
                ]],
            ],
        ], $status),
    ]);

    try {
        app(GoogleAdsKeywordService::class)->keywordIdeas(['imu']);
        $this->fail('Expected exception');
    } catch (GoogleAdsApiException $e) {
        expect($e->getMessage())->toContain($expected);
        expect($e->errorCode)->toBe(array_values($errorCode)[0]);
    }
})->with([
    'test-only developer token' => [403, ['authorizationError' => 'DEVELOPER_TOKEN_NOT_APPROVED'], 'solo per account di test'],
    'permission denied' => [403, ['authorizationError' => 'USER_PERMISSION_DENIED'], 'GOOGLE_ADS_LOGIN_CUSTOMER_ID'],
    'missing keyword planner permission' => [403, ['authorizationError' => 'ACTION_NOT_PERMITTED'], 'ricerca keyword'],
    'wrong customer id' => [400, ['requestError' => 'INVALID_CUSTOMER_ID'], 'GOOGLE_ADS_CUSTOMER_ID'],
    'customer not found' => [401, ['authenticationError' => 'CUSTOMER_NOT_FOUND'], 'Customer ID non trovato'],
    'quota exhausted' => [429, ['quotaError' => 'RESOURCE_EXHAUSTED'], 'Quota Google Ads API esaurita'],
    'rate limited' => [429, ['quotaError' => 'RESOURCE_TEMPORARILY_EXHAUSTED'], 'riprova tra qualche minuto'],
]);

test('explains that keyword planning needs basic access when the token only has explorer access', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ads-token', 'expires_in' => 3599]),
        'googleads.googleapis.com/*' => Http::response([
            'error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'details' => [['errors' => [[
                'errorCode' => ['authorizationError' => 'DEVELOPER_TOKEN_NOT_APPROVED'],
                'message' => 'This method is not allowed for use with explorer access. Please apply for basic or standard access.',
            ]]]]],
        ], 403),
    ]);

    try {
        app(GoogleAdsKeywordService::class)->keywordIdeas(['imu']);
        $this->fail('Expected exception');
    } catch (GoogleAdsApiException $e) {
        expect($e->errorCode)->toBe('EXPLORER_ACCESS_NOT_ALLOWED');
        expect($e->getMessage())->toContain('Di base (Basic)');
    }
});

test('falls back to the Google message for unknown error codes', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ads-token', 'expires_in' => 3599]),
        'googleads.googleapis.com/*' => Http::response([
            'error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'details' => [['errors' => [['errorCode' => ['fieldError' => 'SOMETHING_ODD'], 'message' => 'Field is odd.']]]]],
        ], 400),
    ]);

    expect(fn () => app(GoogleAdsKeywordService::class)->keywordIdeas(['imu']))
        ->toThrow(GoogleAdsApiException::class, 'Errore Google Ads API: Field is odd.');
});

test('explains an expired refresh token', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    expect(fn () => app(GoogleAdsKeywordService::class)->keywordIdeas(['imu']))
        ->toThrow(GoogleAdsApiException::class, 'pulso:google-ads-auth');
});

test('reuses the cached access token across requests', function () {
    fakeGoogleAdsIdeas();
    $service = app(GoogleAdsKeywordService::class);

    $service->keywordIdeas(['uno']);
    $service->keywordIdeas(['due']);

    Http::assertSentCount(3);
});

test('maps historical metrics with close variants', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ads-token', 'expires_in' => 3599]),
        'googleads.googleapis.com/v26/customers/4445556666:generateKeywordHistoricalMetrics' => Http::response([
            'results' => [['text' => 'scarpe', 'closeVariants' => ['scarpa'], 'keywordMetrics' => googleAdsKeywordMetrics(5000, 'LOW')]],
        ]),
    ]);

    $metrics = app(GoogleAdsKeywordService::class)->keywordMetrics(['scarpe', 'scarpa']);

    expect($metrics[0])->toMatchArray(['keyword' => 'scarpe', 'volume' => 5000, 'competition' => 'LOW', 'close_variants' => ['scarpa']]);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'HistoricalMetrics') && $request['keywords'] === ['scarpe', 'scarpa']);
});

test('suggests geo targets restricted to Italy', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ads-token', 'expires_in' => 3599]),
        'googleads.googleapis.com/v26/geoTargetConstants:suggest' => Http::response([
            'geoTargetConstantSuggestions' => [
                ['reach' => '300000', 'geoTargetConstant' => ['resourceName' => 'geoTargetConstants/20590', 'id' => '20590', 'name' => 'Ferrara', 'countryCode' => 'IT', 'targetType' => 'Province', 'status' => 'ENABLED', 'canonicalName' => 'Province of Ferrara,Emilia-Romagna,Italy']],
                ['geoTargetConstant' => ['resourceName' => 'geoTargetConstants/1008463', 'id' => '1008463', 'name' => 'Ferrara', 'countryCode' => 'IT', 'targetType' => 'City', 'status' => 'ENABLED', 'canonicalName' => 'Ferrara,Emilia-Romagna,Italy']],
                ['geoTargetConstant' => ['resourceName' => 'geoTargetConstants/999', 'id' => '999', 'name' => 'Old', 'status' => 'REMOVAL_PLANNED']],
            ],
        ]),
    ]);

    $targets = app(GoogleAdsKeywordService::class)->searchGeoTargets('Ferrara');

    expect($targets)->toHaveCount(2);
    expect($targets[0])->toBe([
        'id' => '20590',
        'name' => 'Ferrara',
        'type' => 'Province',
        'canonical_name' => 'Province of Ferrara,Emilia-Romagna,Italy',
        'country_code' => 'IT',
        'reach' => 300000,
    ]);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'suggest')
        && $request['countryCode'] === 'IT'
        && $request['locale'] === 'it'
        && $request['locationNames'] === ['names' => ['Ferrara']]);
});

test('resolves location names and IDs', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ads-token', 'expires_in' => 3599]),
        'googleads.googleapis.com/v26/geoTargetConstants:suggest' => Http::response([
            'geoTargetConstantSuggestions' => [['geoTargetConstant' => ['id' => '20590', 'name' => 'Ferrara', 'status' => 'ENABLED']]],
        ]),
    ]);

    $service = app(GoogleAdsKeywordService::class);

    expect($service->resolveLocations(['Ferrara', 'geoTargetConstants/2380']))->toEqualCanonicalizing(['20590', '2380']);
    expect($service->resolveLocations([]))->toBe(['2380']);
});

test('resolves language codes and rejects unknown ones', function () {
    $service = app(GoogleAdsKeywordService::class);

    expect($service->languageId(null))->toBe('1004');
    expect($service->languageId('it-IT'))->toBe('1004');
    expect($service->languageId('EN'))->toBe('1000');
    expect($service->languageId('1010'))->toBe('1010');
    expect(fn () => $service->languageId('klingon'))->toThrow(GoogleAdsApiException::class);
});

test('requires seeds or a url', function () {
    expect(fn () => app(GoogleAdsKeywordService::class)->keywordIdeas([' '], null))
        ->toThrow(GoogleAdsApiException::class, 'almeno una keyword');
});

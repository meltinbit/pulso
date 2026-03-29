<?php

use App\Exceptions\GaApiException;
use App\Exceptions\GaPermissionException;
use App\Exceptions\GaQuotaExceededException;
use App\Models\AnalyticsCache;
use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Services\GaClientService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();

    $this->connection = GaConnection::factory()->create();
    $this->property = GaProperty::factory()->create([
        'user_id' => $this->connection->user_id,
        'ga_connection_id' => $this->connection->id,
        'property_id' => '123456789',
    ]);
});

test('runReport calls GA API and returns data', function () {
    Http::fake([
        'analyticsdata.googleapis.com/*' => Http::response([
            'rows' => [['metricValues' => [['value' => '100']]]],
        ]),
    ]);

    $service = app(GaClientService::class);
    $result = $service->runReport($this->property, [
        'dateRanges' => [['startDate' => '7daysAgo', 'endDate' => 'today']],
        'metrics' => [['name' => 'activeUsers']],
    ]);

    expect($result)->toHaveKey('rows');
    expect($result['rows'][0]['metricValues'][0]['value'])->toBe('100');
});

test('runReport uses cache on second call', function () {
    Http::fake([
        'analyticsdata.googleapis.com/*' => Http::response([
            'rows' => [['metricValues' => [['value' => '100']]]],
        ]),
    ]);

    $service = app(GaClientService::class);
    $params = [
        'dateRanges' => [['startDate' => '7daysAgo', 'endDate' => 'today']],
        'metrics' => [['name' => 'activeUsers']],
    ];

    $service->runReport($this->property, $params);
    $service->runReport($this->property, $params);

    Http::assertSentCount(1);
});

test('runReport caches result in analytics_cache table', function () {
    Http::fake([
        'analyticsdata.googleapis.com/*' => Http::response(['rows' => []]),
    ]);

    $service = app(GaClientService::class);
    $service->runReport($this->property, [
        'dateRanges' => [['startDate' => '7daysAgo', 'endDate' => 'today']],
        'metrics' => [['name' => 'sessions']],
    ]);

    expect(AnalyticsCache::where('ga_property_id', $this->property->id)->count())->toBe(1);

    $cached = AnalyticsCache::first();
    expect($cached->report_type)->toBe('core');
    expect($cached->expires_at->isFuture())->toBeTrue();
});

test('expired cache is refreshed', function () {
    Http::fake([
        'analyticsdata.googleapis.com/*' => Http::sequence()
            ->push(['rows' => [['metricValues' => [['value' => 'old']]]]])
            ->push(['rows' => [['metricValues' => [['value' => 'new']]]]]),
    ]);

    $service = app(GaClientService::class);
    $params = [
        'dateRanges' => [['startDate' => '7daysAgo', 'endDate' => 'today']],
        'metrics' => [['name' => 'activeUsers']],
    ];

    $service->runReport($this->property, $params);

    // Expire the cache
    AnalyticsCache::query()->update(['expires_at' => now()->subMinute()]);

    $result = $service->runReport($this->property, $params);
    expect($result['rows'][0]['metricValues'][0]['value'])->toBe('new');
    Http::assertSentCount(2);
});

test('runReport throws GaQuotaExceededException on 429', function () {
    Http::fake([
        'analyticsdata.googleapis.com/*' => Http::response(['error' => 'quota'], 429),
    ]);

    $service = app(GaClientService::class);

    expect(fn () => $service->runReport($this->property, [
        'dateRanges' => [['startDate' => '7daysAgo', 'endDate' => 'today']],
        'metrics' => [['name' => 'activeUsers']],
    ]))->toThrow(GaQuotaExceededException::class);
});

test('runReport throws GaPermissionException on 403', function () {
    Http::fake([
        'analyticsdata.googleapis.com/*' => Http::response(['error' => 'forbidden'], 403),
    ]);

    $service = app(GaClientService::class);

    expect(fn () => $service->runReport($this->property, [
        'dateRanges' => [['startDate' => '7daysAgo', 'endDate' => 'today']],
        'metrics' => [['name' => 'activeUsers']],
    ]))->toThrow(GaPermissionException::class);
});

test('runReport throws GaApiException on other errors', function () {
    Http::fake([
        'analyticsdata.googleapis.com/*' => Http::response(['error' => 'server'], 500),
    ]);

    $service = app(GaClientService::class);

    expect(fn () => $service->runReport($this->property, [
        'dateRanges' => [['startDate' => '7daysAgo', 'endDate' => 'today']],
        'metrics' => [['name' => 'activeUsers']],
    ]))->toThrow(GaApiException::class);
});

test('runRealtimeReport calls correct endpoint', function () {
    Http::fake([
        'analyticsdata.googleapis.com/v1beta/properties/123456789:runRealtimeReport' => Http::response([
            'rows' => [['metricValues' => [['value' => '5']]]],
        ]),
    ]);

    $service = app(GaClientService::class);
    $result = $service->runRealtimeReport($this->property, [
        'metrics' => [['name' => 'activeUsers']],
    ]);

    expect($result['rows'][0]['metricValues'][0]['value'])->toBe('5');
});

test('runFunnelReport calls v1alpha endpoint', function () {
    Http::fake([
        'analyticsdata.googleapis.com/v1alpha/properties/123456789:runFunnelReport' => Http::response([
            'funnelVisualization' => ['rows' => []],
        ]),
    ]);

    $service = app(GaClientService::class);
    $result = $service->runFunnelReport($this->property, [
        'dateRanges' => [['startDate' => '30daysAgo', 'endDate' => 'today']],
        'funnel' => ['isOpenFunnel' => false, 'steps' => []],
    ]);

    expect($result)->toHaveKey('funnelVisualization');
});

test('clearCache removes all entries for property', function () {
    Http::fake([
        'analyticsdata.googleapis.com/*' => Http::response(['rows' => []]),
    ]);

    $service = app(GaClientService::class);

    $service->runReport($this->property, ['metrics' => [['name' => 'a']]]);
    $service->runReport($this->property, ['metrics' => [['name' => 'b']]]);

    expect(AnalyticsCache::where('ga_property_id', $this->property->id)->count())->toBe(2);

    $deleted = $service->clearCache($this->property);
    expect($deleted)->toBe(2);
    expect(AnalyticsCache::where('ga_property_id', $this->property->id)->count())->toBe(0);
});

test('different params produce different cache keys', function () {
    Http::fake([
        'analyticsdata.googleapis.com/*' => Http::response(['rows' => []]),
    ]);

    $service = app(GaClientService::class);

    $service->runReport($this->property, ['metrics' => [['name' => 'activeUsers']]]);
    $service->runReport($this->property, ['metrics' => [['name' => 'sessions']]]);

    Http::assertSentCount(2);
    expect(AnalyticsCache::where('ga_property_id', $this->property->id)->count())->toBe(2);
});

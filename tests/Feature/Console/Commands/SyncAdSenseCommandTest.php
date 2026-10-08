<?php

use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Models\PropertyAdsenseMetric;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-05-20 08:00:00');
});

test('syncs only properties whose connection granted adsense access', function () {
    $granted = GaProperty::factory()
        ->for(GaConnection::factory()->state(['scopes' => 'analytics.readonly adsense.readonly']), 'gaConnection')
        ->create(['website_url' => 'https://example.com', 'timezone' => 'UTC']);
    GaProperty::factory()
        ->for(GaConnection::factory()->state(['scopes' => 'analytics.readonly']), 'gaConnection')
        ->create(['website_url' => 'https://other.com']);

    Http::fake([
        'adsense.googleapis.com/v2/accounts' => Http::response(['accounts' => [['name' => 'accounts/pub-123', 'state' => 'READY']]]),
        'adsense.googleapis.com/v2/accounts/pub-123/reports:generate*' => Http::response([
            'headers' => [['name' => 'DATE'], ['name' => 'ESTIMATED_EARNINGS']],
            'rows' => [['cells' => [['value' => '2026-05-19'], ['value' => '2.00']]]],
        ]),
    ]);

    $this->artisan('adsense:sync', ['--days' => 2])->assertSuccessful();

    expect(PropertyAdsenseMetric::where('dimension', 'total')->pluck('ga_property_id')->unique()->all())->toBe([$granted->id]);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'other.com'));
});

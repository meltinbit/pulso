<?php

use App\Mcp\Servers\PulsoServer;
use App\Mcp\Tools\GetKeywordGapTool;
use App\Mcp\Tools\GetKeywordIdeasTool;
use App\Mcp\Tools\GetKeywordMetricsTool;
use App\Mcp\Tools\SearchGeoTargetsTool;
use App\Models\GaProperty;
use App\Models\PropertySearchQuery;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    configureGoogleAds();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->property = GaProperty::factory()->for($this->user)->create(['display_name' => 'Calcoli Site']);

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ads-token', 'expires_in' => 3599]),
        'googleads.googleapis.com/v26/customers/*:generateKeywordIdeas' => Http::response([
            'results' => [
                ['text' => 'aliquote imu', 'keywordIdeaMetrics' => googleAdsKeywordMetrics(800, 'LOW')],
                ['text' => 'calcolo imu', 'keywordIdeaMetrics' => googleAdsKeywordMetrics(5400, 'HIGH')],
            ],
        ]),
        'googleads.googleapis.com/v26/customers/*:generateKeywordHistoricalMetrics' => Http::response([
            'results' => [['text' => 'calcolo imu', 'keywordMetrics' => googleAdsKeywordMetrics(5400)]],
        ]),
        'googleads.googleapis.com/v26/geoTargetConstants:suggest' => Http::response([
            'geoTargetConstantSuggestions' => [
                ['geoTargetConstant' => ['id' => '20590', 'name' => 'Ferrara', 'targetType' => 'Province', 'status' => 'ENABLED', 'canonicalName' => 'Province of Ferrara,Emilia-Romagna,Italy']],
            ],
        ]),
    ]);
});

test('get-keyword-ideas returns compact rows sorted by volume', function () {
    $response = PulsoServer::tool(GetKeywordIdeasTool::class, ['seeds' => ['imu'], 'limit' => 10]);

    $response->assertOk()
        ->assertSee('"cols":["kw","vol","trend%","comp","comp_idx","cpc_lo","cpc_hi"]')
        ->assertSee('["calcolo imu",5400,50,"H",73,0.12,1.46]')
        ->assertSee('["aliquote imu",800,50,"L",73,0.12,1.46]')
        ->assertDontSee('"months"')
        ->assertDontSee('gsc_pos');
});

test('get-keyword-ideas adds Search Console columns when a property is given', function () {
    PropertySearchQuery::factory()->for($this->property, 'gaProperty')->create([
        'date' => now()->subDays(3), 'query' => 'calcolo imu', 'clicks' => 7, 'impressions' => 90, 'position' => 6.4,
    ]);

    PulsoServer::tool(GetKeywordIdeasTool::class, ['seeds' => 'imu', 'property_id' => $this->property->id])
        ->assertOk()
        ->assertSee('"property":"Calcoli Site"')
        ->assertSee('"gsc_pos","gsc_impr","gsc_clicks"')
        ->assertSee('1.46,6.4,90,7]')
        ->assertSee('1.46,null,null,null]');
});

test('get-keyword-ideas resolves a location name through geo target suggestions', function () {
    PulsoServer::tool(GetKeywordIdeasTool::class, ['seeds' => ['imu'], 'location' => 'Ferrara'])
        ->assertOk()
        ->assertSee('"geo":["20590"]');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'generateKeywordIdeas')
        && $request['geoTargetConstants'] === ['geoTargetConstants/20590']);
});

test('get-keyword-ideas returns a readable error instead of a stack trace', function () {
    configureGoogleAds(['developer_token' => null]);

    PulsoServer::tool(GetKeywordIdeasTool::class, ['seeds' => ['imu']])
        ->assertHasErrors(['Google Ads non è configurato: mancano GOOGLE_ADS_DEVELOPER_TOKEN nel file .env.']);
});

test('get-keyword-ideas cannot read another user property', function () {
    $other = GaProperty::factory()->create();

    PulsoServer::tool(GetKeywordIdeasTool::class, ['seeds' => ['imu'], 'property_id' => $other->id]);
})->throws(ModelNotFoundException::class);

test('get-keyword-metrics returns monthly volumes aligned with months', function () {
    PulsoServer::tool(GetKeywordMetricsTool::class, ['keywords' => ['calcolo imu']])
        ->assertOk()
        ->assertSee('"months":["2025-11","2025-12","2026-01"')
        ->assertSee('[1000,1000,1000,1300,1300,1300,1300,1300,1300,1500,1500,1500]');
});

test('search-geo-targets lists ids, names and types', function () {
    PulsoServer::tool(SearchGeoTargetsTool::class, ['query' => 'Ferrara'])
        ->assertOk()
        ->assertSee('["20590","Ferrara","Province","Province of Ferrara,Emilia-Romagna,Italy"]');
});

test('get-keyword-gap summarizes present and missing keywords', function () {
    PropertySearchQuery::factory()->for($this->property, 'gaProperty')->create([
        'date' => now()->subDays(3), 'query' => 'Calcolo IMU', 'clicks' => 7, 'impressions' => 90, 'position' => 6.4,
    ]);

    PulsoServer::tool(GetKeywordGapTool::class, ['property_id' => $this->property->id, 'seeds' => ['imu']])
        ->assertOk()
        ->assertSee('"summary":{"total":2,"present":1,"missing":1,"missing_volume":800}')
        ->assertSee('["calcolo imu",5400');

    PulsoServer::tool(GetKeywordGapTool::class, ['property_id' => $this->property->id, 'seeds' => ['imu'], 'only_gap' => true])
        ->assertOk()
        ->assertSee('["aliquote imu",800')
        ->assertDontSee('["calcolo imu"');
});

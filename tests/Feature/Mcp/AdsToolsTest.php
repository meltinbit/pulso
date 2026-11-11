<?php

use App\Mcp\Servers\PulsoServer;
use App\Mcp\Tools\GetAdsCampaignsTool;
use App\Mcp\Tools\GetAdsSearchTermsTool;
use App\Mcp\Tools\GetPaidOrganicGapTool;
use App\Mcp\Tools\ListAdsAccountsTool;
use App\Models\GaProperty;
use App\Models\PropertySearchQuery;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-11 10:00:00');
    configureGoogleAds();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/**
 * Fake Google Ads: each GAQL query is answered by the first callback whose
 * FROM clause matches.
 *
 * @param  array<string, Closure(string): array>  $byResource
 */
function fakeGoogleAdsSearch(array $byResource): void
{
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ads-token', 'expires_in' => 3599]),
        'googleads.googleapis.com/*' => function (Request $request) use ($byResource) {
            foreach ($byResource as $resource => $answer) {
                if (str_contains($request['query'], "FROM {$resource} ")) {
                    return Http::response(['results' => $answer($request['query'])]);
                }
            }

            return Http::response(['results' => []]);
        },
    ]);
}

function adsMetrics(int $costEuro, int $clicks, int $impressions, float $conversions, float $value = 0): array
{
    return [
        'costMicros' => (string) ($costEuro * 1_000_000),
        'clicks' => (string) $clicks,
        'impressions' => (string) $impressions,
        'conversions' => $conversions,
        'conversionsValue' => $value,
    ];
}

it('lists the accounts under the manager', function () {
    fakeGoogleAdsSearch(['customer_client' => fn () => [
        ['customerClient' => ['id' => '4445556666', 'descriptiveName' => 'Calcolato', 'manager' => false, 'status' => 'ENABLED', 'currencyCode' => 'EUR']],
    ]]);

    PulsoServer::tool(ListAdsAccountsTool::class, [])
        ->assertOk()
        ->assertSee('["4445556666","Calcolato",false,"ENABLED","EUR"]');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'customers/1112223333/googleAds:search')
        && ! $request->hasHeader('developer-token'));
});

it('returns campaign performance with derived metrics and the previous period', function () {
    fakeGoogleAdsSearch(['campaign' => fn (string $query) => str_contains($query, "'2026-10-12' AND '2026-11-10'")
        ? [
            ['customer' => ['currencyCode' => 'EUR'], 'campaign' => ['id' => '1', 'name' => 'Brand', 'status' => 'ENABLED', 'advertisingChannelType' => 'SEARCH'], 'metrics' => adsMetrics(20, 100, 1000, 10, 300)],
            ['customer' => ['currencyCode' => 'EUR'], 'campaign' => ['id' => '2', 'name' => 'Generic', 'status' => 'PAUSED', 'advertisingChannelType' => 'SEARCH'], 'metrics' => adsMetrics(80, 200, 4000, 2)],
        ]
        : [['campaign' => ['id' => '1', 'name' => 'Brand', 'status' => 'ENABLED', 'advertisingChannelType' => 'SEARCH'], 'metrics' => adsMetrics(50, 100, 1000, 5)]],
    ]);

    PulsoServer::tool(GetAdsCampaignsTool::class, [])
        ->assertOk()
        ->assertSee('"from":"2026-10-12","to":"2026-11-10"')
        ->assertSee('"currency":"EUR"')
        ->assertSee('"totals":{"cost":100,"clicks":300,"impressions":5000,"conversions":12,"conversion_value":300,"ctr":6,"cpc":0.33,"cpa":8.33,"roas":3}')
        ->assertSee('"change_pct":{"cost":100,"conversions":140')
        ->assertSee('["2","Generic","PAUSED","SEARCH",80,200,4000,5,0.4,2,0,40,null]');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'customers/4445556666/googleAds:search')
        && str_contains($request['query'], "campaign.status != 'REMOVED'"));
});

it('sums search terms across ad groups and reports spend without conversions', function () {
    fakeGoogleAdsSearch(['search_term_view' => fn () => [
        ['searchTermView' => ['searchTerm' => 'calcolo forfettario', 'status' => 'NONE'], 'metrics' => adsMetrics(10, 20, 100, 2)],
        ['searchTermView' => ['searchTerm' => 'calcolo forfettario', 'status' => 'ADDED'], 'metrics' => adsMetrics(5, 10, 50, 1)],
        ['searchTermView' => ['searchTerm' => 'forfettario gratis pdf', 'status' => 'NONE'], 'metrics' => adsMetrics(7, 9, 80, 0)],
    ]]);

    PulsoServer::tool(GetAdsSearchTermsTool::class, ['customer_id' => '444-555-6666'])
        ->assertOk()
        ->assertSee('"summary":{"terms":2,"cost":22,"conversions":3,"cost_without_conversions":7}')
        ->assertSee('["calcolo forfettario","ADDED",15,30,150,3,0,5]');

    PulsoServer::tool(GetAdsSearchTermsTool::class, ['only_converting' => true])
        ->assertDontSee('gratis pdf');
});

it('finds converting paid terms without a strong organic page', function () {
    $property = GaProperty::factory()->for($this->user)->create(['website_url' => 'https://example.com']);

    foreach ([
        ['calcolo forfettario', 'https://example.com/forfettario/', 30, 300, 14.0],
        ['regime forfettario', 'https://example.com/regime/', 50, 400, 3.0],
    ] as [$query, $page, $clicks, $impressions, $position]) {
        PropertySearchQuery::create(['ga_property_id' => $property->id, 'date' => '2026-10-20', 'query' => $query, 'page' => $page, 'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => 1, 'position' => $position]);
    }

    fakeGoogleAdsSearch(['search_term_view' => fn () => [
        ['searchTermView' => ['searchTerm' => 'Calcolo Forfettario', 'status' => 'NONE'], 'metrics' => adsMetrics(30, 40, 200, 6)],
        ['searchTermView' => ['searchTerm' => 'regime forfettario', 'status' => 'NONE'], 'metrics' => adsMetrics(10, 10, 90, 2)],
        ['searchTermView' => ['searchTerm' => 'partita iva forfettaria quanto costa', 'status' => 'NONE'], 'metrics' => adsMetrics(12, 8, 60, 3)],
        ['searchTermView' => ['searchTerm' => 'forfettario gratis', 'status' => 'NONE'], 'metrics' => adsMetrics(9, 9, 90, 0)],
    ]]);

    PulsoServer::tool(GetPaidOrganicGapTool::class, ['property_id' => $property->id])
        ->assertOk()
        ->assertSee('"summary":{"converting_terms":3,"missing":1,"weak":1,"conversions_without_strong_page":9}')
        ->assertSee('["Calcolo Forfettario","weak",6,30,5,300,30,14,"https:\/\/example.com\/forfettario\/"]')
        ->assertSee('["partita iva forfettaria quanto costa","missing",3,12,4,0,0,null,null]')
        ->assertDontSee('regime forfettario')
        ->assertDontSee('gratis');

    PulsoServer::tool(GetPaidOrganicGapTool::class, ['property_id' => $property->id, 'include_strong' => true])
        ->assertSee('"regime forfettario","strong"');
});

it('returns readable errors from google ads', function () {
    configureGoogleAds(['refresh_token' => null]);

    PulsoServer::tool(GetAdsCampaignsTool::class, [])
        ->assertHasErrors(['Google Ads non è configurato']);
});

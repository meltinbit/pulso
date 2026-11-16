<?php

use App\Mcp\Servers\PulsoServer;
use App\Mcp\Tools\DeleteSitemapTool;
use App\Mcp\Tools\GetPropertyIndexStatusTool;
use App\Mcp\Tools\ListSitemapsTool;
use App\Mcp\Tools\QuerySearchAnalyticsTool;
use App\Mcp\Tools\SubmitSitemapTool;
use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Models\User;
use App\Services\ConnectionHealthService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-16 10:00:00');

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $connection = GaConnection::factory()->for($this->user)->create([
        'access_token' => 'fake-token',
        'token_expires_at' => now()->addHour(),
    ]);
    $this->property = GaProperty::factory()->for($this->user)->for($connection, 'gaConnection')
        ->searchConsoleOnly('sc-domain:example.com')->create(['display_name' => 'Example']);
    $this->sitemapsUrl = 'searchconsole.googleapis.com/webmasters/v3/sites/'.urlencode('sc-domain:example.com').'/sitemaps';
});

test('list-sitemaps returns status, dates and counts', function () {
    Http::fake([
        $this->sitemapsUrl => Http::response(['sitemap' => [[
            'path' => 'https://example.com/sitemap.xml',
            'lastSubmitted' => '2026-11-01T10:00:00Z',
            'lastDownloaded' => '2026-11-15T03:00:00Z',
            'isPending' => false,
            'isSitemapsIndex' => true,
            'warnings' => '2',
            'errors' => '1',
            'contents' => [['type' => 'web', 'submitted' => '120']],
        ]]]),
    ]);

    PulsoServer::tool(ListSitemapsTool::class, ['property_id' => $this->property->id])
        ->assertOk()
        ->assertSee('"search_console_site": "sc-domain:example.com"')
        ->assertSee('"last_downloaded": "2026-11-15T03:00:00Z"')
        ->assertSee('"errors": 1')
        ->assertSee('"submitted": 120');
});

test('submit-sitemap sends a PUT for the sitemap of the site', function () {
    Http::fake(['searchconsole.googleapis.com/*' => Http::response(null, 204)]);

    PulsoServer::tool(SubmitSitemapTool::class, [
        'property_id' => $this->property->id,
        'sitemap_url' => 'https://example.com/sitemap.xml',
    ])->assertOk()->assertSee('"submitted":"https://example.com/sitemap.xml"');

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === 'https://'.$this->sitemapsUrl.'/'.urlencode('https://example.com/sitemap.xml'));
});

test('submit-sitemap refuses a sitemap of another site', function () {
    PulsoServer::tool(SubmitSitemapTool::class, [
        'property_id' => $this->property->id,
        'sitemap_url' => 'https://other.com/sitemap.xml',
    ])->assertHasErrors(['non è sul sito']);

    Http::assertNothingSent();
});

test('a read only Google account gets a reconnect message', function () {
    Http::fake(['searchconsole.googleapis.com/*' => Http::response([
        'error' => [
            'code' => 403,
            'message' => 'Request had insufficient authentication scopes.',
            'status' => 'PERMISSION_DENIED',
            'details' => [['reason' => 'ACCESS_TOKEN_SCOPE_INSUFFICIENT']],
        ],
    ], 403)]);

    PulsoServer::tool(SubmitSitemapTool::class, [
        'property_id' => $this->property->id,
        'sitemap_url' => 'https://example.com/sitemap.xml',
    ])->assertHasErrors(['solo il permesso di lettura']);
});

test('delete-sitemap removes only a listed sitemap', function () {
    Http::fake([
        $this->sitemapsUrl => Http::response(['sitemap' => [['path' => 'https://example.com/old-sitemap.xml']]]),
        $this->sitemapsUrl.'/*' => Http::response(null, 204),
    ]);

    PulsoServer::tool(DeleteSitemapTool::class, [
        'property_id' => $this->property->id,
        'sitemap_url' => 'https://example.com/missing.xml',
    ])->assertHasErrors(['non è tra le sitemap']);

    PulsoServer::tool(DeleteSitemapTool::class, [
        'property_id' => $this->property->id,
        'sitemap_url' => 'https://example.com/old-sitemap.xml',
    ])->assertOk();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), urlencode('https://example.com/old-sitemap.xml')));
});

test('query-search-analytics sends dimensions, filters and search type', function () {
    Http::fake(['searchconsole.googleapis.com/*/searchAnalytics/query' => Http::sequence()
        ->push(['rows' => [['clicks' => 30, 'impressions' => 1000, 'ctr' => 0.03, 'position' => 7.25]]])
        ->push(['rows' => [['keys' => ['ita', 'MOBILE'], 'clicks' => 20, 'impressions' => 600, 'ctr' => 0.0333, 'position' => 6.04]]]),
    ]);

    PulsoServer::tool(QuerySearchAnalyticsTool::class, [
        'property_id' => $this->property->id,
        'from' => '2026-11-01',
        'to' => '2026-11-14',
        'dimensions' => ['country', 'device'],
        'type' => 'image',
        'page_contains' => '/blog/',
        'device' => 'mobile',
    ])
        ->assertOk()
        ->assertSee('"totals":{"clicks":30,"impressions":1000,"ctr":3,"position":7.3}')
        ->assertSee('"cols":["country","device","clicks","impressions","ctr","position"]')
        ->assertSee('["ita","MOBILE",20,600,3.33,6]');

    Http::assertSent(fn (Request $request) => ($request['dimensions'] ?? null) === ['country', 'device']
        && $request['type'] === 'image'
        && $request['dataState'] === 'all'
        && $request['dimensionFilterGroups'][0]['filters'] === [
            ['dimension' => 'page', 'operator' => 'contains', 'expression' => '/blog/'],
            ['dimension' => 'device', 'operator' => 'equals', 'expression' => 'MOBILE'],
        ]);
});

test('query-search-analytics asks hourly data for the hour dimension', function () {
    Http::fake(['searchconsole.googleapis.com/*' => Http::response(['rows' => []])]);

    PulsoServer::tool(QuerySearchAnalyticsTool::class, [
        'property_id' => $this->property->id,
        'dimensions' => ['hour'],
    ])->assertOk()->assertSee('"data_state":"hourly_all"');

    Http::assertSent(fn (Request $request) => $request['dataState'] === 'hourly_all');
});

test('index status explains what to do and returns rich results', function () {
    Http::fake(['searchconsole.googleapis.com/v1/urlInspection/index:inspect' => Http::response([
        'inspectionResult' => [
            'indexStatusResult' => [
                'verdict' => 'NEUTRAL',
                'coverageState' => 'Crawled - currently not indexed',
                'crawledAs' => 'MOBILE',
            ],
            'richResultsResult' => [
                'verdict' => 'FAIL',
                'detectedItems' => [[
                    'richResultType' => 'FAQ',
                    'items' => [['issues' => [['issueMessage' => 'Missing field "name"', 'severity' => 'ERROR']]]],
                ]],
            ],
        ],
    ])]);

    PulsoServer::tool(GetPropertyIndexStatusTool::class, [
        'property_id' => $this->property->id,
        'urls' => ['https://example.com/a'],
    ])
        ->assertOk()
        ->assertSee('"crawled_as": "MOBILE"')
        ->assertSee('ERROR: Missing field \"name\"')
        ->assertSee('Improve and differentiate the content');
});

test('health flags Google accounts with read only Search Console access', function () {
    GaConnection::factory()->for($this->user)->create(['scopes' => 'analytics.readonly webmasters.readonly']);

    $issues = collect(app(ConnectionHealthService::class)->report($this->user)['connections'])->pluck('issue')->filter();

    expect($issues)->toHaveCount(1)
        ->and($issues->first())->toContain('sola lettura');
});

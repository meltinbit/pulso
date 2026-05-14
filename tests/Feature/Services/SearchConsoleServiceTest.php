<?php

use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Services\SearchConsoleService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    $connection = GaConnection::factory()->create([
        'access_token' => 'fake-token',
        'token_expires_at' => now()->addHour(),
    ]);
    $this->property = GaProperty::factory()
        ->for($connection, 'gaConnection')
        ->create(['website_url' => 'https://example.com']);
});

test('uses sc-domain when matching Domain property exists', function () {
    Http::fake([
        'searchconsole.googleapis.com/webmasters/v3/sites' => Http::response([
            'siteEntry' => [
                ['siteUrl' => 'sc-domain:example.com'],
                ['siteUrl' => 'https://other.test/'],
            ],
        ]),
        'searchconsole.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response([
            'rows' => [[
                'keys' => ['hello world', 'https://example.com/foo'],
                'clicks' => 5, 'impressions' => 100, 'ctr' => 0.05, 'position' => 4.2,
            ]],
        ]),
    ]);

    $rows = app(SearchConsoleService::class)->fetchSearchQueries($this->property, '2026-04-21');

    expect($rows)->toHaveCount(1);
    expect($rows[0]['query'])->toBe('hello world');
    expect($rows[0]['page'])->toBe('/foo');
    expect($rows[0]['ctr'])->toBe(5.0);

    Http::assertSent(fn ($request) => str_contains($request->url(), urlencode('sc-domain:example.com')));
});

test('falls back to URL-prefix site when no Domain property exists', function () {
    Http::fake([
        'searchconsole.googleapis.com/webmasters/v3/sites' => Http::response([
            'siteEntry' => [
                ['siteUrl' => 'https://www.example.com/'],
                ['siteUrl' => 'sc-domain:other.test'],
            ],
        ]),
        'searchconsole.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response([
            'rows' => [],
        ]),
    ]);

    app(SearchConsoleService::class)->fetchSearchQueries($this->property, '2026-04-21');

    Http::assertSent(fn ($request) => str_contains($request->url(), urlencode('https://www.example.com/'))
        && ! str_contains($request->url(), 'sc-domain'));
});

test('returns empty array and skips API call when no site matches the host', function () {
    Http::fake([
        'searchconsole.googleapis.com/webmasters/v3/sites' => Http::response([
            'siteEntry' => [['siteUrl' => 'sc-domain:other.test']],
        ]),
    ]);

    $rows = app(SearchConsoleService::class)->fetchSearchQueries($this->property, '2026-04-21');

    expect($rows)->toBe([]);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'searchAnalytics/query'));
});

test('caches the resolved site so listSites is not called twice', function () {
    Http::fake([
        'searchconsole.googleapis.com/webmasters/v3/sites' => Http::response([
            'siteEntry' => [['siteUrl' => 'sc-domain:example.com']],
        ]),
        'searchconsole.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response(['rows' => []]),
    ]);

    $service = app(SearchConsoleService::class);
    $service->fetchSearchQueries($this->property, '2026-04-21');
    $service->fetchSearchQueries($this->property, '2026-04-22');

    Http::assertSentCount(3); // 1 listSites + 2 query
});

test('forgets cached site on API failure so next call re-resolves', function () {
    Http::fakeSequence('searchconsole.googleapis.com/webmasters/v3/sites')
        ->push(['siteEntry' => [['siteUrl' => 'sc-domain:example.com']]])
        ->push(['siteEntry' => [['siteUrl' => 'https://example.com/']]]);

    Http::fake([
        'searchconsole.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::sequence()
            ->push(['error' => 'forbidden'], 403)
            ->push(['error' => 'forbidden'], 403)
            ->push(['error' => 'forbidden'], 403)
            ->push(['rows' => []]),
    ]);

    $service = app(SearchConsoleService::class);
    $service->fetchSearchQueries($this->property, '2026-04-21');
    $service->fetchSearchQueries($this->property, '2026-04-22');

    Http::assertSent(fn ($request) => str_contains($request->url(), urlencode('https://example.com/')));
});

test('inspects urls and returns index status details', function () {
    Http::fake([
        'searchconsole.googleapis.com/webmasters/v3/sites' => Http::response([
            'siteEntry' => [['siteUrl' => 'sc-domain:example.com']],
        ]),
        'searchconsole.googleapis.com/v1/urlInspection/index:inspect' => Http::response([
            'inspectionResult' => [
                'inspectionResultLink' => 'https://search.google.com/test/rich-results?id=123',
                'indexStatusResult' => [
                    'verdict' => 'NEUTRAL',
                    'coverageState' => 'Crawled - currently not indexed',
                    'indexingState' => 'INDEXING_ALLOWED',
                    'robotsTxtState' => 'ALLOWED',
                    'pageFetchState' => 'SUCCESSFUL',
                    'lastCrawlTime' => '2026-05-10T08:00:00Z',
                    'userCanonical' => 'https://example.com/blog/post',
                    'referringUrls' => ['https://example.com/'],
                    'sitemap' => ['https://example.com/sitemap.xml'],
                ],
            ],
        ]),
    ]);

    $rows = app(SearchConsoleService::class)->inspectUrls($this->property, [
        'https://example.com/blog/post',
    ]);

    expect($rows)->toHaveCount(1);
    expect($rows[0]['url'])->toBe('https://example.com/blog/post');
    expect($rows[0]['is_indexed'])->toBeFalse();
    expect($rows[0]['coverage_state'])->toBe('Crawled - currently not indexed');
    expect($rows[0]['inspection_result_link'])->toContain('search.google.com');
});

test('discovers urls from sitemap index and nested sitemap', function () {
    Http::fake([
        'searchconsole.googleapis.com/webmasters/v3/sites' => Http::response([
            'siteEntry' => [['siteUrl' => 'sc-domain:example.com']],
        ]),
        'searchconsole.googleapis.com/webmasters/v3/sites/*/sitemaps' => Http::response([
            'sitemap' => [
                ['path' => 'https://example.com/sitemap_index.xml', 'isSitemapsIndex' => true],
            ],
        ]),
        'https://example.com/sitemap_index.xml' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <sitemap><loc>https://example.com/posts.xml</loc></sitemap>
</sitemapindex>
XML),
        'https://example.com/posts.xml' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://example.com/a</loc></url>
  <url><loc>https://www.example.com/b</loc></url>
  <url><loc>https://other.test/c</loc></url>
</urlset>
XML),
    ]);

    $urls = app(SearchConsoleService::class)->discoverUrlsFromSitemaps($this->property, null, 10);

    expect($urls)->toBe([
        'https://example.com/a',
        'https://www.example.com/b',
    ]);
});

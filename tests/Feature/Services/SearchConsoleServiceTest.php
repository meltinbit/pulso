<?php

use App\Exceptions\SearchConsoleApiException;
use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Services\SearchConsoleService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Cache::flush();
    Sleep::fake();
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
                'keys' => ['2026-04-21', 'hello world', 'https://example.com/foo'],
                'clicks' => 5, 'impressions' => 100, 'ctr' => 0.05, 'position' => 4.2,
            ]],
        ]),
    ]);

    $rows = app(SearchConsoleService::class)->fetchDailyRows($this->property, '2026-04-21', '2026-04-21');

    expect($rows)->toHaveCount(1);
    expect($rows[0]['date'])->toBe('2026-04-21');
    expect($rows[0]['query'])->toBe('hello world');
    expect($rows[0]['page'])->toBe('https://example.com/foo');
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

    app(SearchConsoleService::class)->fetchDailyRows($this->property, '2026-04-21', '2026-04-21');

    Http::assertSent(fn ($request) => str_contains($request->url(), urlencode('https://www.example.com/'))
        && ! str_contains($request->url(), 'sc-domain'));
});

test('throws instead of reporting an empty day when no site matches the host', function () {
    Http::fake([
        'searchconsole.googleapis.com/webmasters/v3/sites' => Http::response([
            'siteEntry' => [['siteUrl' => 'sc-domain:other.test']],
        ]),
    ]);

    expect(fn () => app(SearchConsoleService::class)->fetchDailyRows($this->property, '2026-04-21', '2026-04-21'))
        ->toThrow(SearchConsoleApiException::class);

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
    $service->fetchDailyRows($this->property, '2026-04-21', '2026-04-21');
    $service->fetchDailyRows($this->property, '2026-04-22', '2026-04-22');

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

    expect(fn () => $service->fetchDailyRows($this->property, '2026-04-21', '2026-04-21'))
        ->toThrow(SearchConsoleApiException::class);

    $service->fetchDailyRows($this->property, '2026-04-22', '2026-04-22');

    Http::assertSent(fn ($request) => str_contains($request->url(), urlencode('https://example.com/')));
});

test('requests date, query and page dimensions with the maximum row limit', function () {
    Http::fake([
        'searchconsole.googleapis.com/webmasters/v3/sites' => Http::response([
            'siteEntry' => [['siteUrl' => 'sc-domain:example.com']],
        ]),
        'searchconsole.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response(['rows' => []]),
    ]);

    app(SearchConsoleService::class)->fetchDailyRows($this->property, '2026-04-21', '2026-04-27');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'searchAnalytics/query')) {
            return false;
        }

        return $request['dimensions'] === ['date', 'query', 'page']
            && $request['rowLimit'] === SearchConsoleService::ROW_LIMIT
            && $request['startRow'] === 0
            && $request['startDate'] === '2026-04-21'
            && $request['endDate'] === '2026-04-27';
    });
});

test('paginates on startRow until a partial page comes back', function () {
    $fullPage = array_map(fn (int $i) => [
        'keys' => ['2026-04-21', "query {$i}", 'https://example.com/'.$i],
        'clicks' => 1, 'impressions' => 10, 'ctr' => 0.1, 'position' => 2.0,
    ], range(1, SearchConsoleService::ROW_LIMIT));

    Http::fake([
        'searchconsole.googleapis.com/webmasters/v3/sites' => Http::response([
            'siteEntry' => [['siteUrl' => 'sc-domain:example.com']],
        ]),
        'searchconsole.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::sequence()
            ->push(['rows' => $fullPage])
            ->push(['rows' => [[
                'keys' => ['2026-04-21', 'last one', 'https://example.com/last'],
                'clicks' => 3, 'impressions' => 30, 'ctr' => 0.1, 'position' => 5.0,
            ]]]),
    ]);

    $rows = app(SearchConsoleService::class)->fetchDailyRows($this->property, '2026-04-21', '2026-04-21');

    expect($rows)->toHaveCount(SearchConsoleService::ROW_LIMIT + 1);
    expect(end($rows)['query'])->toBe('last one');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'searchAnalytics/query')
        && $request['startRow'] === SearchConsoleService::ROW_LIMIT);
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

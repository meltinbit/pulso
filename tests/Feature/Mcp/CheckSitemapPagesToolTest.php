<?php

use App\Mcp\Servers\PulsoServer;
use App\Mcp\Tools\CheckSitemapPagesTool;
use App\Models\GaProperty;
use App\Models\PropertySearchQuery;
use App\Models\User;
use App\Services\SearchConsoleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Carbon::setTestNow('2026-11-11 10:00:00');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->property = GaProperty::factory()->for($this->user)->create(['website_url' => 'https://calcolato.it']);

    $this->mock(SearchConsoleService::class)
        ->shouldReceive('discoverUrlsFromSitemaps')
        ->andReturn([
            'https://calcolato.it/ok/',
            'https://calcolato.it/sparita/',
            'https://calcolato.it/spostata/',
            'https://calcolato.it/copia/',
            'https://calcolato.it/nascosta/',
            'https://calcolato.it/nuova/',
            'https://calcolato.it/rotta/',
            'https://calcolato.it/blog/ok/',
        ]);

    PropertySearchQuery::factory()->create(['ga_property_id' => $this->property->id, 'date' => '2026-11-01', 'page' => 'https://calcolato.it/ok/', 'impressions' => 40]);
    PropertySearchQuery::factory()->create(['ga_property_id' => $this->property->id, 'date' => '2026-11-01', 'page' => 'https://calcolato.it/blog/ok/', 'impressions' => 5]);
});

function sitemapTestPage(string $canonical, string $extraHead = ''): string
{
    return "<html><head><title>x</title><link rel=\"canonical\" href=\"{$canonical}\">{$extraHead}</head><body></body></html>";
}

it('reports broken, redirected, duplicate, hidden and unseen pages from the sitemap', function () {
    Http::fake([
        'calcolato.it/ok/' => Http::response(sitemapTestPage('HTTPS://Calcolato.it/ok/#top')),
        'calcolato.it/sparita/' => Http::response('', 404),
        'calcolato.it/spostata/' => Http::response('', 301, ['Location' => '/nuova-sede/']),
        'calcolato.it/copia/' => Http::response(sitemapTestPage('/ok/')),
        'calcolato.it/nascosta/' => Http::response(sitemapTestPage('https://calcolato.it/nascosta/', '<meta name="robots" content="noindex, follow">')),
        'calcolato.it/nuova/' => Http::response('<html><head></head></html>', 200, ['X-Robots-Tag' => 'otherbot: noindex']),
        'calcolato.it/rotta/' => Http::response('', 503),
        'calcolato.it/blog/ok/' => Http::response(sitemapTestPage('https://calcolato.it/blog/ok/'), 200, ['X-Robots-Tag' => 'noindex']),
    ]);

    PulsoServer::tool(CheckSitemapPagesTool::class, ['property_id' => $this->property->id])
        ->assertOk()
        ->assertSee('"urls_found":8,"checked":8,"offset":0,"next_offset":null,"ok":1')
        ->assertSee('["https:\/\/calcolato.it\/sparita\/",404,')
        ->assertSee('["not_found"],null]')
        ->assertSee('["redirect"],"https:\/\/calcolato.it\/nuova-sede\/"]')
        ->assertSee('["canonical_elsewhere"],"https:\/\/calcolato.it\/ok\/"]')
        ->assertSee('["noindex"],null]')
        ->assertSee('["missing_canonical","no_impressions"],null]')
        ->assertSee('["server_error"],null]')
        ->assertDontSee('"https:\/\/calcolato.it\/ok\/",200');
});

it('checks one section page by page', function () {
    Http::fake(['*' => Http::response(sitemapTestPage('https://calcolato.it/blog/ok/'))]);

    PulsoServer::tool(CheckSitemapPagesTool::class, ['property_id' => $this->property->id, 'path' => '/blog/', 'only_problems' => false])
        ->assertOk()
        ->assertSee('"urls_found":1,"checked":1')
        ->assertSee('["https:\/\/calcolato.it\/blog\/ok\/",200,');

    PulsoServer::tool(CheckSitemapPagesTool::class, ['property_id' => $this->property->id, 'limit' => 3, 'offset' => 3])
        ->assertSee('"urls_found":8,"checked":3,"offset":3,"next_offset":6');

    Http::assertSent(fn ($request) => str_contains($request->header('User-Agent')[0] ?? '', 'PulsoCheck'));
});

it('accepts the bare host as canonical of the home page', function () {
    Http::fake(['*' => Http::response(sitemapTestPage('https://calcolato.it'))]);

    PulsoServer::tool(CheckSitemapPagesTool::class, ['property_id' => $this->property->id, 'urls' => ['/'], 'only_problems' => false])
        ->assertOk()
        ->assertSee('"source":"urls"')
        ->assertSee('["https:\/\/calcolato.it\/",200,')
        ->assertDontSee('canonical_elsewhere');
});

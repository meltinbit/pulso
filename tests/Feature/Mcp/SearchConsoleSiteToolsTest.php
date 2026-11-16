<?php

use App\Jobs\BackfillSearchConsoleData;
use App\Mcp\Servers\PulsoServer;
use App\Mcp\Tools\AddSearchConsoleSiteTool;
use App\Mcp\Tools\GetSiteVerificationTokenTool;
use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Models\User;
use App\Services\SiteVerificationService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    Queue::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->connection = GaConnection::factory()->for($this->user)->create([
        'google_email' => 'fabio@example.com',
        'access_token' => 'fake-token',
        'token_expires_at' => now()->addHour(),
    ]);
});

function fakeVerificationAndSearchConsole(): void
{
    Http::fake([
        'www.googleapis.com/siteVerification/v1/token' => Http::response(['method' => 'DNS_TXT', 'token' => 'google-site-verification=abc123']),
        'www.googleapis.com/siteVerification/v1/webResource*' => Http::response(['id' => 'dns%3A%2F%2Fnuovo.it', 'owners' => ['fabio@example.com']]),
        'searchconsole.googleapis.com/webmasters/v3/sites/*' => Http::response(null, 204),
    ]);
}

test('sites are parsed into Domain and URL-prefix properties', function () {
    expect(SiteVerificationService::parseSite('Nuovo.it'))->toBe(['type' => 'INET_DOMAIN', 'identifier' => 'nuovo.it', 'site_url' => 'sc-domain:nuovo.it', 'host' => 'nuovo.it'])
        ->and(SiteVerificationService::parseSite('sc-domain:nuovo.it')['site_url'])->toBe('sc-domain:nuovo.it')
        ->and(SiteVerificationService::parseSite('https://www.nuovo.it')['site_url'])->toBe('https://www.nuovo.it/')
        ->and(SiteVerificationService::parseSite('https://www.nuovo.it/blog')['site_url'])->toBe('https://www.nuovo.it/blog/');
});

test('get-site-verification-token returns the DNS record for a domain', function () {
    fakeVerificationAndSearchConsole();

    PulsoServer::tool(GetSiteVerificationTokenTool::class, ['site' => 'nuovo.it'])
        ->assertOk()
        ->assertSee('"token": "google-site-verification=abc123"')
        ->assertSee('Add a TXT record on nuovo.it');

    Http::assertSent(fn (Request $request) => $request['site'] === ['type' => 'INET_DOMAIN', 'identifier' => 'nuovo.it']
        && $request['verificationMethod'] === 'DNS_TXT');
});

test('a domain cannot be verified with a meta tag', function () {
    PulsoServer::tool(GetSiteVerificationTokenTool::class, ['site' => 'nuovo.it', 'method' => 'META'])
        ->assertHasErrors(['solo via DNS']);

    Http::assertNothingSent();
});

test('several Google accounts need google_account', function () {
    GaConnection::factory()->for($this->user)->create(['google_email' => 'altro@example.com']);

    PulsoServer::tool(GetSiteVerificationTokenTool::class, ['site' => 'nuovo.it'])
        ->assertHasErrors(['Più account Google collegati']);
});

test('add-search-console-site verifies, adds the site and creates a Search Console only property', function () {
    fakeVerificationAndSearchConsole();

    PulsoServer::tool(AddSearchConsoleSiteTool::class, ['site' => 'nuovo.it'])
        ->assertOk()
        ->assertSee('"status": "created_search_console_only"');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'webResource?verificationMethod=DNS_TXT'));
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === 'https://searchconsole.googleapis.com/webmasters/v3/sites/'.urlencode('sc-domain:nuovo.it'));

    $property = GaProperty::where('gsc_site_url', 'sc-domain:nuovo.it')->sole();

    expect($property->hasAnalytics())->toBeFalse()
        ->and($property->website_url)->toBe('https://nuovo.it')
        ->and($property->ga_connection_id)->toBe($this->connection->id);

    Queue::assertPushed(BackfillSearchConsoleData::class);
});

test('add-search-console-site links an existing property with the same domain and submits the sitemap', function () {
    fakeVerificationAndSearchConsole();
    $property = GaProperty::factory()->for($this->user)->for($this->connection, 'gaConnection')
        ->create(['website_url' => 'https://www.nuovo.it']);

    PulsoServer::tool(AddSearchConsoleSiteTool::class, [
        'site' => 'nuovo.it',
        'sitemap_url' => 'https://www.nuovo.it/sitemap.xml',
    ])
        ->assertOk()
        ->assertSee('"status": "linked_to_existing"')
        ->assertSee('"sitemap": "https://www.nuovo.it/sitemap.xml"');

    expect($property->fresh()->gsc_site_url)->toBe('sc-domain:nuovo.it')
        ->and(GaProperty::count())->toBe(1);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/sitemaps/'.urlencode('https://www.nuovo.it/sitemap.xml')));
    Queue::assertNothingPushed();
});

test('a code not yet published is reported without adding the site', function () {
    Http::fake([
        'www.googleapis.com/siteVerification/v1/webResource*' => Http::response(['error' => ['message' => 'The necessary verification token could not be found on your site.']], 400),
    ]);

    PulsoServer::tool(AddSearchConsoleSiteTool::class, ['site' => 'nuovo.it'])
        ->assertHasErrors(['non ha trovato il codice di verifica']);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'searchconsole.googleapis.com'));
    expect(GaProperty::count())->toBe(0);
});

test('an account without the verification scope gets a reconnect message', function () {
    Http::fake([
        'www.googleapis.com/siteVerification/v1/token' => Http::response(['error' => ['message' => 'Request had insufficient authentication scopes.']], 403),
    ]);

    PulsoServer::tool(GetSiteVerificationTokenTool::class, ['site' => 'nuovo.it'])
        ->assertHasErrors(['non ha il permesso di verificare siti']);
});

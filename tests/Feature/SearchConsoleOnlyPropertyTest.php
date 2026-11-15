<?php

use App\Exceptions\GaApiException;
use App\Jobs\BackfillSearchConsoleData;
use App\Jobs\GenerateDailySnapshots;
use App\Mcp\Servers\PulsoServer;
use App\Mcp\Tools\GetPropertySummaryTool;
use App\Mcp\Tools\ListPropertiesTool;
use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Models\PropertySearchQuery;
use App\Models\PropertySnapshot;
use App\Models\User;
use App\Services\ConnectionHealthService;
use App\Services\GaClientService;
use App\Services\GaPropertyDiscoveryService;
use App\Services\SearchConsoleService;
use App\Services\SettingService;
use App\Services\SnapshotAnalyzerService;
use App\Services\TelegramNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->connection = GaConnection::factory()->for($this->user)->create([
        'access_token' => 'fake-token',
        'token_expires_at' => now()->addHour(),
    ]);
});

function fakeSearchConsoleSites(): void
{
    Http::fake([
        'searchconsole.googleapis.com/webmasters/v3/sites' => Http::response([
            'siteEntry' => [
                ['siteUrl' => 'sc-domain:solo-gsc.it', 'permissionLevel' => 'siteOwner'],
                ['siteUrl' => 'https://www.prefisso.it/', 'permissionLevel' => 'siteFullUser'],
                ['siteUrl' => 'https://non-verificato.it/', 'permissionLevel' => 'siteUnverifiedUser'],
            ],
        ]),
    ]);
}

test('website url is derived from the search console site', function () {
    expect(SearchConsoleService::websiteUrlFor('sc-domain:example.com'))->toBe('https://example.com')
        ->and(SearchConsoleService::websiteUrlFor('https://www.example.com/'))->toBe('https://www.example.com');
});

test('properties page lists verified search console sites', function () {
    fakeSearchConsoleSites();
    $discovery = Mockery::mock(GaPropertyDiscoveryService::class);
    $discovery->shouldReceive('listAccessibleProperties')->andReturn([]);
    app()->instance(GaPropertyDiscoveryService::class, $discovery);

    GaProperty::factory()->for($this->user)->for($this->connection, 'gaConnection')->create([
        'display_name' => 'Prefisso GA4',
        'website_url' => 'https://prefisso.it',
    ]);

    $this->actingAs($this->user)
        ->get('/properties')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('availableSites', 2)
            ->where('availableSites.0.site_url', 'sc-domain:solo-gsc.it')
            ->where('availableSites.0.covered_by', null)
            ->where('availableSites.1.site_url', 'https://www.prefisso.it/')
            ->where('availableSites.1.covered_by', 'Prefisso GA4'));
});

test('a search console site can be added without a GA4 property', function () {
    fakeSearchConsoleSites();
    Queue::fake();

    $this->actingAs($this->user)
        ->post('/properties/search-console', [
            'site_url' => 'sc-domain:solo-gsc.it',
            'ga_connection_id' => $this->connection->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $property = GaProperty::where('gsc_site_url', 'sc-domain:solo-gsc.it')->sole();

    expect($property->property_id)->toBeNull()
        ->and($property->hasAnalytics())->toBeFalse()
        ->and($property->display_name)->toBe('solo-gsc.it')
        ->and($property->website_url)->toBe('https://solo-gsc.it')
        ->and($property->user_id)->toBe($this->user->id);

    Queue::assertPushed(BackfillSearchConsoleData::class, fn ($job) => $job->property->is($property));

    $this->actingAs($this->user)->post('/properties/search-console', [
        'site_url' => 'sc-domain:solo-gsc.it',
        'ga_connection_id' => $this->connection->id,
    ]);

    expect(GaProperty::where('gsc_site_url', 'sc-domain:solo-gsc.it')->count())->toBe(1);
    Queue::assertPushed(BackfillSearchConsoleData::class, 1);
});

test('a site missing from the account search console list is refused', function () {
    fakeSearchConsoleSites();
    Queue::fake();

    $this->actingAs($this->user)
        ->post('/properties/search-console', [
            'site_url' => 'https://non-verificato.it/',
            'ga_connection_id' => $this->connection->id,
        ])
        ->assertSessionHas('error');

    expect(GaProperty::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('another users connection cannot be used', function () {
    $other = GaConnection::factory()->create();

    $this->actingAs($this->user)
        ->post('/properties/search-console', [
            'site_url' => 'sc-domain:solo-gsc.it',
            'ga_connection_id' => $other->id,
        ])
        ->assertSessionHasErrors('ga_connection_id');
});

test('search console queries use the stored site', function () {
    $property = GaProperty::factory()->for($this->user)->for($this->connection, 'gaConnection')
        ->searchConsoleOnly('sc-domain:solo-gsc.it')->create();

    Http::fake([
        'searchconsole.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response(['rows' => []]),
    ]);

    app(SearchConsoleService::class)->fetchDailyRows($property, '2026-11-01', '2026-11-01');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), urlencode('sc-domain:solo-gsc.it')));
});

test('daily snapshots skip search console only properties', function () {
    Carbon::setTestNow(Carbon::parse('2026-04-24 09:00:00', 'UTC'));
    GaProperty::factory()->for($this->user)->for($this->connection, 'gaConnection')->searchConsoleOnly()->create();

    (new GenerateDailySnapshots)->handle(
        app(SnapshotAnalyzerService::class),
        app(TelegramNotificationService::class),
        app(SettingService::class),
    );

    expect(PropertySnapshot::count())->toBe(0);
    Http::assertNothingSent();
});

test('GA4 reports are refused for search console only properties', function () {
    $property = GaProperty::factory()->for($this->user)->for($this->connection, 'gaConnection')->searchConsoleOnly()->create();

    app(GaClientService::class)->runReport($property, ['metrics' => [['name' => 'activeUsers']]]);
})->throws(GaApiException::class, 'solo su Search Console');

test('health does not expect snapshots for search console only properties', function () {
    GaProperty::factory()->for($this->user)->for($this->connection, 'gaConnection')->searchConsoleOnly()->create();

    $property = app(ConnectionHealthService::class)->report($this->user)['properties'][0];

    expect($property['has_analytics'])->toBeFalse()
        ->and(collect($property['issues'])->filter(fn (string $issue) => str_contains($issue, 'snapshot')))->toBeEmpty();
});

test('mcp tools describe search console only properties', function () {
    $this->actingAs($this->user);
    $property = GaProperty::factory()->for($this->user)->for($this->connection, 'gaConnection')
        ->searchConsoleOnly('sc-domain:solo-gsc.it')->create(['display_name' => 'solo-gsc.it']);

    PropertySearchQuery::create([
        'ga_property_id' => $property->id,
        'date' => '2026-11-10',
        'query' => 'calcolo solo',
        'page' => 'https://solo-gsc.it/',
        'clicks' => 4,
        'impressions' => 90,
        'ctr' => 4.4,
        'position' => 6.1,
    ]);

    PulsoServer::tool(ListPropertiesTool::class)
        ->assertOk()
        ->assertSee('"has_analytics": false')
        ->assertSee('"search_console_site": "sc-domain:solo-gsc.it"');

    PulsoServer::tool(GetPropertySummaryTool::class, ['property_id' => $property->id])
        ->assertOk()
        ->assertSee('"source": "search_console_only"')
        ->assertSee('"query": "calcolo solo"');
});

<?php

use App\Jobs\BackfillAdSenseData;
use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Models\PropertyAdsenseMetric;
use App\Models\User;
use App\Services\AdSenseSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-05-20 08:00:00');

    $this->user = User::factory()->create();
    $this->connection = GaConnection::factory()->for($this->user)->create(['scopes' => 'analytics.readonly adsense.readonly']);
    $this->property = GaProperty::factory()->for($this->user)->for($this->connection, 'gaConnection')->create([
        'website_url' => 'https://example.com',
        'timezone' => 'UTC',
    ]);
});

test('asks to reconnect when the connection lacks the adsense scope', function () {
    $this->connection->update(['scopes' => 'analytics.readonly webmasters.readonly']);

    $this->actingAs($this->user)
        ->get(route('adsense.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('adsense/index')
            ->where('status', 'missing_scope')
            ->where('report', null));
});

test('asks for a first sync when nothing is stored', function () {
    $this->actingAs($this->user)
        ->get(route('adsense.index'))
        ->assertInertia(fn (Assert $page) => $page->where('status', 'no_data'));
});

test('renders totals with ratios weighted over the range and a previous period comparison', function () {
    PropertyAdsenseMetric::factory()->for($this->property, 'gaProperty')->create([
        'date' => '2026-05-19', 'earnings' => 6, 'page_views' => 1000, 'clicks' => 10, 'ad_requests' => 100, 'matched_ad_requests' => 90,
    ]);
    PropertyAdsenseMetric::factory()->for($this->property, 'gaProperty')->create([
        'date' => '2026-05-18', 'earnings' => 2, 'page_views' => 3000, 'clicks' => 6, 'ad_requests' => 100, 'matched_ad_requests' => 70,
    ]);
    // Previous 7 day window.
    PropertyAdsenseMetric::factory()->for($this->property, 'gaProperty')->create([
        'date' => '2026-05-10', 'earnings' => 4, 'page_views' => 2000, 'clicks' => 8, 'ad_requests' => 50, 'matched_ad_requests' => 50,
    ]);
    PropertyAdsenseMetric::factory()->for($this->property, 'gaProperty')->breakdown('country', 'Italy')->create(['date' => '2026-05-19', 'earnings' => 5]);
    PropertyAdsenseMetric::factory()->for($this->property, 'gaProperty')->breakdown('country', 'Spain')->create(['date' => '2026-05-19', 'earnings' => 1]);

    $this->actingAs($this->user)
        ->get(route('adsense.index', ['period' => '7d']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('status', 'ready')
            ->where('report.from', '2026-05-14')
            ->where('report.to', '2026-05-20')
            ->where('report.totals.earnings', 8)
            ->where('report.totals.page_views', 4000)
            ->where('report.totals.page_rpm', 2)
            ->where('report.totals.page_ctr', 0.4)
            ->where('report.totals.cpc', 0.5)
            ->where('report.totals.coverage', 80)
            ->where('report.previous.totals.earnings', 4)
            ->where('report.changes.earnings', 100)
            ->has('report.daily', 2)
            ->where('report.breakdowns.country.0.name', 'Italy')
            ->has('report.breakdowns.country', 2));
});

test('does not show another users data', function () {
    $other = User::factory()->create();

    $this->actingAs($other)
        ->get(route('adsense.index'))
        ->assertInertia(fn (Assert $page) => $page->where('hasProperty', false)->where('report', null));
});

test('first manual sync downloads recent days and queues the backfill', function () {
    Queue::fake();
    Http::fake([
        'adsense.googleapis.com/v2/accounts' => Http::response(['accounts' => [['name' => 'accounts/pub-123', 'state' => 'READY']]]),
        'adsense.googleapis.com/v2/accounts/pub-123/reports:generate*' => Http::response([
            'headers' => [['name' => 'DATE'], ['name' => 'ESTIMATED_EARNINGS'], ['name' => 'PAGE_VIEWS']],
            'rows' => [['cells' => [['value' => '2026-05-19'], ['value' => '1.50'], ['value' => '300']]]],
        ]),
    ]);

    $this->actingAs($this->user)
        ->post(route('adsense.sync'))
        ->assertRedirect()
        ->assertSessionHas('success');

    Queue::assertPushed(BackfillAdSenseData::class, fn (BackfillAdSenseData $job) => $job->property->is($this->property));
    expect(PropertyAdsenseMetric::where('date', '2026-05-19')->exists())->toBeTrue();

    $this->actingAs($this->user)
        ->get(route('adsense.index'))
        ->assertInertia(fn (Assert $page) => $page->where('backfill.state', 'queued'));
});

test('first sync explains which domains have data when the property domain has none', function () {
    Queue::fake();
    Http::fake([
        'adsense.googleapis.com/v2/accounts' => Http::response(['accounts' => [['name' => 'accounts/pub-123', 'state' => 'READY']]]),
        'adsense.googleapis.com/v2/accounts/pub-123/reports:generate*' => function ($request) {
            if (str_contains($request->url(), 'dimensions=DOMAIN_NAME')) {
                return Http::response(['rows' => [
                    ['cells' => [['value' => 'calcolato.it'], ['value' => '12166']]],
                    ['cells' => [['value' => 'www.other.it'], ['value' => '10']]],
                ]]);
            }

            return Http::response(['headers' => [], 'rows' => []]);
        },
    ]);

    $this->actingAs($this->user)
        ->post(route('adsense.sync'))
        ->assertSessionHas('error', fn (string $message) => str_contains($message, 'example.com')
            && str_contains($message, 'calcolato.it, other.it'));

    Queue::assertNothingPushed();
});

test('backfill job reports its progress to the page', function () {
    Http::fake([
        'adsense.googleapis.com/v2/accounts' => Http::response(['accounts' => [['name' => 'accounts/pub-123', 'state' => 'READY']]]),
        'adsense.googleapis.com/v2/accounts/pub-123/reports:generate*' => Http::response([
            'headers' => [['name' => 'DATE'], ['name' => 'ESTIMATED_EARNINGS']],
            'rows' => [['cells' => [['value' => '2026-05-19'], ['value' => '1.00']]]],
        ]),
    ]);

    (new BackfillAdSenseData($this->property, 1))->handle(app(AdSenseSyncService::class));

    expect(app(AdSenseSyncService::class)->backfillStatus($this->property))
        ->toMatchArray(['state' => 'done', 'days' => 1]);
});

test('a failed backfill job is shown with its error', function () {
    (new BackfillAdSenseData($this->property))->failed(new RuntimeException('HTTP 429'));

    $this->actingAs($this->user)
        ->get(route('adsense.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('backfill.state', 'failed')
            ->where('backfill.error', 'HTTP 429'));
});

test('manual sync is refused without the adsense scope', function () {
    $this->connection->update(['scopes' => 'analytics.readonly']);

    $this->actingAs($this->user)
        ->post(route('adsense.sync'))
        ->assertSessionHas('error');

    Http::assertNothingSent();
});

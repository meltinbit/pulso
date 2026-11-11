<?php

use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Models\McpCallLog;
use App\Models\PropertySnapshot;
use App\Models\User;
use App\Services\BingSyncService;
use App\Services\SettingService;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow('2026-11-11 10:00:00');
    $this->user = User::factory()->create();
});

it('redirects guests to login', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('reports a healthy property without issues', function () {
    $connection = GaConnection::factory()->for($this->user)->create();
    $property = GaProperty::factory()->for($this->user)->for($connection, 'gaConnection')->create(['website_url' => 'https://example.com']);
    PropertySnapshot::factory()->for($property, 'gaProperty')->create(['snapshot_date' => '2026-11-10']);
    $property->searchQueries()->create(['date' => '2026-11-08', 'query' => 'calcolo', 'page' => 'https://example.com/', 'clicks' => 1, 'impressions' => 10, 'ctr' => 0.1, 'position' => 3]);

    $this->actingAs($this->user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard/health')
            ->where('health.properties.0.last_snapshot', '2026-11-10')
            ->where('health.properties.0.last_search_console', '2026-11-08')
            ->where('health.properties.0.issues', [])
            ->where('health.connections.0.status', 'ok'));
});

it('flags stale data, missing website and expired connections with the fix to apply', function () {
    $connection = GaConnection::factory()->for($this->user)->create();
    app(SettingService::class)->set($this->user->id, "google_connection_alerted_{$connection->id}", '1', 'google');

    $stale = GaProperty::factory()->for($this->user)->for($connection, 'gaConnection')->create(['display_name' => 'A stale', 'website_url' => 'https://example.com']);
    PropertySnapshot::factory()->for($stale, 'gaProperty')->create(['snapshot_date' => '2026-11-01']);
    GaProperty::factory()->for($this->user)->for($connection, 'gaConnection')->create(['display_name' => 'B no url', 'website_url' => null]);

    McpCallLog::factory()->failed()->create();

    $this->actingAs($this->user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('health.connections.0.status', 'expired')
            ->where('health.properties.0.issues', fn ($issues) => collect($issues)->contains(fn ($issue) => str_contains($issue, 'Ultimo snapshot GA4 del 2026-11-01'))
                && collect($issues)->contains(fn ($issue) => str_contains($issue, 'Nessun dato Search Console')))
            ->where('health.properties.1.issues.0', fn ($issue) => str_contains($issue, 'Manca l\'URL del sito'))
            ->where('health.mcp.errors_24h', 1)
            ->where('health.issues_count', fn ($count) => $count >= 4));
});

it('reports bing problems', function () {
    $settings = app(SettingService::class);
    $connection = GaConnection::factory()->for($this->user)->create();
    GaProperty::factory()->for($this->user)->for($connection, 'gaConnection')->create(['website_url' => 'https://example.com', 'bing_site_url' => null]);

    $this->actingAs($this->user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('health.bing.issue', fn ($issue) => str_contains($issue, 'non impostata')));

    $settings->set($this->user->id, BingSyncService::SETTING_KEY, 'key', 'bing', encrypted: true);
    $settings->set($this->user->id, BingSyncService::ALERTED_SETTING_KEY, '1', 'bing');

    $this->actingAs($this->user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('health.bing.key_invalid', true)
            ->where('health.properties.0.issues', fn ($issues) => collect($issues)->contains(fn ($issue) => str_contains($issue, 'Non collegata a Bing'))));
});

it('does not show another users properties', function () {
    GaProperty::factory()->create(['display_name' => 'Someone else']);

    $this->actingAs($this->user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('health.properties', []));
});

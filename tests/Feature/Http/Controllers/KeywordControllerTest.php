<?php

use App\Models\GaProperty;
use App\Models\PropertySearchQuery;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
    Http::preventStrayRequests();
    configureGoogleAds();

    $this->user = User::factory()->create();
    $this->property = GaProperty::factory()->for($this->user)->create();
    $this->user->update(['active_property_id' => $this->property->id]);

    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ads-token', 'expires_in' => 3599]),
        'googleads.googleapis.com/v26/customers/*:generateKeywordIdeas' => Http::response([
            'results' => [
                ['text' => 'calcolo imu', 'keywordIdeaMetrics' => googleAdsKeywordMetrics(5400)],
                ['text' => 'aliquote imu', 'keywordIdeaMetrics' => googleAdsKeywordMetrics(800)],
            ],
        ]),
        'googleads.googleapis.com/v26/geoTargetConstants:suggest' => Http::response([
            'geoTargetConstantSuggestions' => [['geoTargetConstant' => ['id' => '20590', 'name' => 'Ferrara', 'targetType' => 'Province', 'status' => 'ENABLED']]],
        ]),
    ]);
});

test('renders the empty form without calling Google', function () {
    $this->actingAs($this->user)
        ->get(route('keywords.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('keywords/index')
            ->where('configured', true)
            ->where('results', null)
            ->where('filters.location.0.id', '2380'));

    Http::assertNothingSent();
});

test('shows ideas annotated with Search Console data for the active property', function () {
    PropertySearchQuery::factory()->for($this->property, 'gaProperty')->create([
        'date' => now()->subDays(2), 'query' => 'calcolo imu', 'clicks' => 3, 'impressions' => 50, 'position' => 9.2,
    ]);

    $this->actingAs($this->user)
        ->get(route('keywords.index', ['seeds' => 'imu, tasse', 'location' => ['20590:Ferrara (Province)'], 'language' => 'it']))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->has('results', 2)
            ->where('results.0.keyword', 'calcolo imu')
            ->where('results.0.gsc.position', 9.2)
            ->where('results.1.gsc', null)
            ->where('summary.missing', 1)
            ->where('filters.location.0.name', 'Ferrara (Province)')
            ->where('usage.today', 1));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'generateKeywordIdeas')
        && $request['keywordSeed'] === ['keywords' => ['imu', 'tasse']]
        && $request['geoTargetConstants'] === ['geoTargetConstants/20590']);
});

test('shows Google Ads errors as a readable message', function () {
    configureGoogleAds(['daily_operations_limit' => 0]);

    $this->actingAs($this->user)
        ->get(route('keywords.index', ['seeds' => 'imu']))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('results', null)
            ->where('error', fn (string $error) => str_contains($error, 'Limite giornaliero')));

    Http::assertNothingSent();
});

test('flags missing configuration', function () {
    configureGoogleAds(['customer_id' => null]);

    $this->actingAs($this->user)
        ->get(route('keywords.index', ['seeds' => 'imu']))
        ->assertInertia(fn (Assert $page) => $page->where('configured', false)->where('results', null));

    Http::assertNothingSent();
});

test('returns geo target suggestions as JSON', function () {
    $this->actingAs($this->user)
        ->getJson(route('keywords.geo-targets', ['q' => 'Ferrara']))
        ->assertSuccessful()
        ->assertJsonPath('targets.0.id', '20590')
        ->assertJsonPath('targets.0.type', 'Province');
});

test('requires authentication', function () {
    $this->get(route('keywords.index'))->assertRedirect(route('login'));
});

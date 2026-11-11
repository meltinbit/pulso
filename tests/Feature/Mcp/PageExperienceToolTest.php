<?php

use App\Mcp\Servers\PulsoServer;
use App\Mcp\Tools\GetPageExperienceTool;
use App\Models\GaProperty;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->property = GaProperty::factory()->for($this->user)->create(['website_url' => 'https://calcolato.it/']);
    app(SettingService::class)->set($this->user->id, 'google_api_key', 'AIzaTest', 'google', encrypted: true);
});

function cruxMetric(float $p75): array
{
    return ['histogram' => [], 'percentiles' => ['p75' => $p75]];
}

function cruxRecord(array $metrics): array
{
    return ['record' => [
        'key' => ['origin' => 'https://calcolato.it'],
        'metrics' => $metrics,
        'collectionPeriod' => ['firstDate' => ['year' => 2026, 'month' => 10, 'day' => 13], 'lastDate' => ['year' => 2026, 'month' => 11, 'day' => 9]],
    ]];
}

it('rates the core web vitals of the site and adds the weekly trend', function () {
    Http::fake([
        'chromeuxreport.googleapis.com/v1/records:queryRecord*' => Http::response(cruxRecord([
            'largest_contentful_paint' => cruxMetric(2100),
            'interaction_to_next_paint' => cruxMetric(240),
            'cumulative_layout_shift' => cruxMetric(0.02),
            'experimental_time_to_first_byte' => cruxMetric(1900),
        ])),
        'chromeuxreport.googleapis.com/v1/records:queryHistoryRecord*' => Http::response(['record' => [
            'metrics' => ['largest_contentful_paint' => ['percentilesTimeseries' => ['p75s' => [2600, 2100]]], 'cumulative_layout_shift' => ['percentilesTimeseries' => ['p75s' => ['NaN', 0.02]]]],
            'collectionPeriods' => [
                ['firstDate' => ['year' => 2026, 'month' => 10, 'day' => 6], 'lastDate' => ['year' => 2026, 'month' => 11, 'day' => 2]],
                ['firstDate' => ['year' => 2026, 'month' => 10, 'day' => 13], 'lastDate' => ['year' => 2026, 'month' => 11, 'day' => 9]],
            ],
        ]]),
    ]);

    PulsoServer::tool(GetPageExperienceTool::class, ['property_id' => $this->property->id, 'history' => true, 'weeks' => 2])
        ->assertOk()
        ->assertSee('"scope":"origin","target":"https:\/\/calcolato.it","form_factor":"phone"')
        ->assertSee('"period":{"from":"2026-10-13","to":"2026-11-09"},"core_web_vitals":"failed"')
        ->assertSee('"interaction_to_next_paint":{"p75":240,"rating":"needs_improvement"}')
        ->assertSee('"experimental_time_to_first_byte":{"p75":1900,"rating":"poor"}')
        ->assertSee('"rows":[["2026-11-02",2600,null],["2026-11-09",2100,0.02]]');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'queryRecord?key=AIzaTest')
        && $request['origin'] === 'https://calcolato.it' && $request['formFactor'] === 'PHONE');
});

it('falls back to the origin when a page has no crux data and adds a lighthouse audit', function () {
    Http::fake([
        'chromeuxreport.googleapis.com/*' => function ($request) {
            return isset($request['url'])
                ? Http::response(['error' => ['code' => 404, 'message' => 'chrome ux report data not found']], 404)
                : Http::response(cruxRecord(['largest_contentful_paint' => cruxMetric(1800), 'interaction_to_next_paint' => cruxMetric(90), 'cumulative_layout_shift' => cruxMetric(0.01)]));
        },
        'www.googleapis.com/pagespeedonline/*' => Http::response(['lighthouseResult' => [
            'categories' => ['performance' => ['score' => 0.72]],
            'audits' => [
                'largest-contentful-paint' => ['score' => 0.4, 'displayValue' => '3.9 s'],
                'render-blocking-insight' => ['title' => 'Render blocking requests', 'score' => 0, 'displayValue' => 'Est savings of 600 ms', 'metricSavings' => ['FCP' => 600, 'LCP' => 600]],
                'unused-javascript' => ['title' => 'Reduce unused JavaScript', 'score' => 0.5, 'details' => ['overallSavingsMs' => 300]],
                'image-delivery-insight' => ['title' => 'Improve image delivery', 'score' => 1, 'metricSavings' => ['LCP' => 0]],
            ],
        ]]),
    ]);

    PulsoServer::tool(GetPageExperienceTool::class, ['property_id' => $this->property->id, 'url' => '/calcolo/imu/roma', 'lighthouse' => true])
        ->assertOk()
        ->assertSee('"scope":"origin"')
        ->assertSee('i dati sono dell')
        ->assertSee('"core_web_vitals":"passed"')
        ->assertSee('"lighthouse":{"score":72,"metrics":{"lcp":"3.9 s"')
        ->assertSee('"opportunities":[{"id":"render-blocking-insight","title":"Render blocking requests","value":"Est savings of 600 ms","savings":{"FCP":600,"LCP":600}},{"id":"unused-javascript"')
        ->assertDontSee('image-delivery-insight');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'runPagespeed') && $request['url'] === 'https://calcolato.it/calcolo/imu/roma' && $request['strategy'] === 'mobile');
});

it('explains how to set up the api key', function () {
    app(SettingService::class)->set($this->user->id, 'google_api_key', null, 'google', encrypted: true);

    PulsoServer::tool(GetPageExperienceTool::class, ['property_id' => $this->property->id])
        ->assertHasErrors(['Impostazioni → Google']);
});

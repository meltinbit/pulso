<?php

use App\Mcp\Servers\PulsoServer;
use App\Mcp\Tools\GetPropertyFunnelTool;
use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $connection = GaConnection::factory()->for($this->user)->create();
    $this->property = GaProperty::factory()->for($this->user)->for($connection, 'gaConnection')->create([
        'display_name' => 'Calcolato',
    ]);
});

function funnelTableResponse(): array
{
    return [
        'funnelTable' => [
            'dimensionHeaders' => [['name' => 'funnelStepName']],
            'metricHeaders' => [
                ['name' => 'activeUsers'],
                ['name' => 'funnelStepCompletionRate'],
                ['name' => 'funnelStepAbandonments'],
                ['name' => 'funnelStepAbandonmentRate'],
            ],
            'rows' => [
                ['dimensionValues' => [['value' => '1. page_view /forfettario']], 'metricValues' => [['value' => '1000'], ['value' => '0.4'], ['value' => '600'], ['value' => '0.6']]],
                ['dimensionValues' => [['value' => '2. calcolo_eseguito']], 'metricValues' => [['value' => '400'], ['value' => '0'], ['value' => '0'], ['value' => '0']]],
            ],
        ],
        'funnelVisualization' => ['rows' => []],
    ];
}

it('runs a funnel report and returns compact per step metrics', function () {
    Http::fake(['analyticsdata.googleapis.com/*' => Http::response(funnelTableResponse())]);

    $response = PulsoServer::tool(GetPropertyFunnelTool::class, [
        'property_id' => $this->property->id,
        'steps' => [
            ['page_contains' => '/forfettario'],
            ['event_name' => 'calcolo_eseguito'],
        ],
        'from' => '2026-10-01',
        'to' => '2026-10-31',
    ]);

    $response->assertOk()
        ->assertSee('"overall_conversion":40')
        ->assertSee('"users":1000')
        ->assertSee('"completion_rate":40')
        ->assertSee('"abandonment_rate":60');

    Http::assertSent(function (Request $request): bool {
        $steps = $request['funnel']['steps'];

        return str_contains($request->url(), "/v1alpha/properties/{$this->property->property_id}:runFunnelReport")
            && $request['dateRanges'][0] === ['startDate' => '2026-10-01', 'endDate' => '2026-10-31']
            && $request['funnel']['isOpenFunnel'] === false
            && $steps[0]['filterExpression']['funnelEventFilter']['eventName'] === 'page_view'
            && $steps[0]['filterExpression']['funnelEventFilter']['funnelParameterFilterExpression']['funnelParameterFilter']['stringFilter']['value'] === '/forfettario'
            && $steps[1]['filterExpression']['funnelEventFilter'] === ['eventName' => 'calcolo_eseguito'];
    });
});

it('requires at least two steps', function () {
    $response = PulsoServer::tool(GetPropertyFunnelTool::class, [
        'property_id' => $this->property->id,
        'steps' => [['event_name' => 'page_view']],
    ]);

    $response->assertHasErrors();
});

it('requires an event or a page on each step', function () {
    $response = PulsoServer::tool(GetPropertyFunnelTool::class, [
        'property_id' => $this->property->id,
        'steps' => [['event_name' => 'page_view'], ['name' => 'empty']],
    ]);

    $response->assertHasErrors();
});

it('explains what to fix when google denies access', function () {
    Http::fake(['analyticsdata.googleapis.com/*' => Http::response([], 403)]);

    $response = PulsoServer::tool(GetPropertyFunnelTool::class, [
        'property_id' => $this->property->id,
        'steps' => [['event_name' => 'session_start'], ['event_name' => 'calcolo_eseguito']],
    ]);

    $response->assertHasErrors(['Impostazioni → Google']);
});

it('cannot run a funnel on another users property', function () {
    $other = GaProperty::factory()->create();

    PulsoServer::tool(GetPropertyFunnelTool::class, [
        'property_id' => $other->id,
        'steps' => [['event_name' => 'session_start'], ['event_name' => 'calcolo_eseguito']],
    ]);
})->throws(ModelNotFoundException::class);

<?php

use App\Mcp\Servers\PulsoServer;
use App\Mcp\Tools\GetQueryPageMapTool;
use App\Models\GaProperty;
use App\Models\PropertySearchQuery;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-11-11 10:00:00');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->property = GaProperty::factory()->for($this->user)->create(['website_url' => 'https://calcolato.it']);
});

function searchRow(GaProperty $property, string $query, string $path, int $clicks, int $impressions, float $position, string $date = '2026-11-01'): void
{
    PropertySearchQuery::factory()->create([
        'ga_property_id' => $property->id, 'date' => $date, 'query' => $query, 'page' => 'https://calcolato.it'.$path,
        'clicks' => $clicks, 'impressions' => $impressions, 'position' => $position,
    ]);
}

it('finds queries split between competing pages', function () {
    searchRow($this->property, 'imu roma', '/calcolo/imu/roma', 30, 600, 4.0);
    searchRow($this->property, 'imu roma', '/calcolo/imu/roma#esempio', 0, 200, 8.0);
    searchRow($this->property, 'imu roma', '/blog/imu-2026', 5, 300, 9.0);
    searchRow($this->property, 'imu roma', '/calcolo/imu', 0, 20, 30.0);
    searchRow($this->property, 'stipendio netto', '/calcolo/stipendio-netto', 50, 900, 3.0);
    searchRow($this->property, 'stipendio netto', '/calcolo/stipendio-netto/ral-35000', 0, 40, 20.0);
    searchRow($this->property, 'imu milano', '/calcolo/imu/milano', 3, 20, 6.0);
    searchRow($this->property, 'imu milano', '/blog/imu-2026', 1, 15, 7.0);
    searchRow($this->property, 'imu roma', '/blog/imu-2026', 99, 9999, 1.0, '2026-07-01');

    PulsoServer::tool(GetQueryPageMapTool::class, ['property_id' => $this->property->id])
        ->assertOk()
        ->assertSee('"summary":{"queries_checked":2,"cannibalized":1,"impressions_elsewhere":320')
        ->assertSee('"query":"imu roma","clicks":35,"impressions":1120,"main_page":"\/calcolo\/imu\/roma","impressions_elsewhere":320')
        ->assertSee('"pages":[["\/calcolo\/imu\/roma",30,800,71.4,5],["\/blog\/imu-2026",5,300,26.8,9]]')
        ->assertDontSee('stipendio netto')
        ->assertDontSee('imu milano');
});

it('lists pages with their top queries and flags queries owned by another page', function () {
    searchRow($this->property, 'imu roma', '/calcolo/imu/roma', 30, 600, 4.0);
    searchRow($this->property, 'imu roma', '/blog/imu-2026', 5, 300, 9.0);
    searchRow($this->property, 'imu 2026', '/blog/imu-2026', 12, 400, 5.0);

    PulsoServer::tool(GetQueryPageMapTool::class, ['property_id' => $this->property->id, 'view' => 'pages', 'page' => '/blog/'])
        ->assertOk()
        ->assertSee('{"page":"\/blog\/imu-2026","clicks":17,"impressions":700,"queries":2,"top_queries":[["imu 2026",12,400,5,null],["imu roma",5,300,9,"\/calcolo\/imu\/roma"]]}')
        ->assertDontSee('"page":"\/calcolo\/imu\/roma","clicks"');
});

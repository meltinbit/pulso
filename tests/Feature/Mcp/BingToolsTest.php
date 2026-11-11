<?php

use App\Mcp\Servers\PulsoServer;
use App\Mcp\Tools\GetPropertyBingSearchTool;
use App\Mcp\Tools\GetPropertyBingTrafficTool;
use App\Mcp\Tools\GetPropertySummaryTool;
use App\Mcp\Tools\SubmitUrlsToBingTool;
use App\Models\GaProperty;
use App\Models\PropertyBingCrawlStat;
use App\Models\PropertyBingSearchStat;
use App\Models\PropertyBingTraffic;
use App\Models\PropertySnapshot;
use App\Models\User;
use App\Services\BingSyncService;
use App\Services\SettingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-11 10:00:00');

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->property = GaProperty::factory()->for($this->user)->create([
        'display_name' => 'Calcolato',
        'website_url' => 'https://www.example.com',
        'bing_site_url' => 'https://www.example.com/',
    ]);
});

function bingWeek(GaProperty $property, string $weekStart, string $dimension, string $value, int $clicks, int $impressions, ?float $position): void
{
    PropertyBingSearchStat::create([
        'ga_property_id' => $property->id,
        'dimension' => $dimension,
        'week_start' => $weekStart,
        'value' => $value,
        'clicks' => $clicks,
        'impressions' => $impressions,
        'avg_impression_position' => $position,
    ]);
}

it('sums bing queries over whole weeks with impression weighted position', function () {
    bingWeek($this->property, '2026-10-23', 'query', 'calcolo imu', 4, 100, 2.0);
    bingWeek($this->property, '2026-10-30', 'query', 'calcolo imu', 6, 300, 4.0);
    bingWeek($this->property, '2026-10-30', 'query', 'forfettario', 1, 50, 9.0);
    bingWeek($this->property, '2026-10-30', 'page', 'https://www.example.com/imu/', 9, 400, 3.0);
    bingWeek($this->property, '2026-09-04', 'query', 'calcolo imu', 99, 999, 1.0);

    PulsoServer::tool(GetPropertyBingSearchTool::class, [
        'property_id' => $this->property->id,
        'from' => '2026-10-25',
        'to' => '2026-11-02',
    ])
        ->assertOk()
        ->assertSee('"covered":["2026-10-23","2026-11-05"]')
        ->assertSee('"cols":["query","clicks","impressions","ctr","position"]')
        ->assertSee('["calcolo imu",10,400,2.5,3.5]')
        ->assertSee('["forfettario",1,50,2,9]')
        ->assertDontSee('imu/');
});

it('filters bing pages by text', function () {
    bingWeek($this->property, '2026-10-30', 'page', 'https://www.example.com/imu/', 9, 400, 3.0);
    bingWeek($this->property, '2026-10-30', 'page', 'https://www.example.com/blog/', 2, 40, 8.0);

    PulsoServer::tool(GetPropertyBingSearchTool::class, [
        'property_id' => $this->property->id,
        'dimension' => 'page',
        'contains' => '/blog/',
    ])
        ->assertOk()
        ->assertSee('blog')
        ->assertDontSee('imu');
});

it('explains how to link a property without a bing site', function () {
    $this->property->update(['bing_site_url' => null]);

    PulsoServer::tool(GetPropertyBingTrafficTool::class, ['property_id' => $this->property->id])
        ->assertHasErrors(['Impostazioni → Bing']);
});

it('returns bing traffic totals, previous period and crawl health', function () {
    foreach (['2026-10-01' => 5, '2026-10-20' => 10, '2026-11-05' => 20] as $date => $clicks) {
        PropertyBingTraffic::create(['ga_property_id' => $this->property->id, 'date' => $date, 'clicks' => $clicks, 'impressions' => $clicks * 10]);
    }
    PropertyBingCrawlStat::create(['ga_property_id' => $this->property->id, 'date' => '2026-10-20', 'in_index' => 100, 'code_4xx' => 1]);
    PropertyBingCrawlStat::create(['ga_property_id' => $this->property->id, 'date' => '2026-11-05', 'in_index' => 120, 'code_4xx' => 2]);

    PulsoServer::tool(GetPropertyBingTrafficTool::class, [
        'property_id' => $this->property->id,
        'from' => '2026-10-15',
        'to' => '2026-11-11',
    ])
        ->assertOk()
        ->assertSee('"totals":{"clicks":30,"impressions":300,"ctr":10}')
        ->assertSee('"change_pct":{"clicks":500,"impressions":500}')
        ->assertSee('"in_index_start":100,"in_index_end":120')
        ->assertSee('"code_4xx":3');
});

it('adds a bing section to the property summary', function () {
    PropertySnapshot::factory()->for($this->property, 'gaProperty')->create(['snapshot_date' => now()->subDay()]);
    PropertyBingTraffic::create(['ga_property_id' => $this->property->id, 'date' => '2026-11-07', 'clicks' => 8, 'impressions' => 90]);

    PulsoServer::tool(GetPropertySummaryTool::class, ['property_id' => $this->property->id])
        ->assertOk()
        ->assertSee('"clicks_28d": 8');
});

describe('submit urls to bing', function () {
    beforeEach(function () {
        app(SettingService::class)->set($this->user->id, BingSyncService::SETTING_KEY, 'test-key', 'bing', encrypted: true);
    });

    it('checks the quota and submits the urls', function () {
        Http::fake([
            'ssl.bing.com/webmaster/api.svc/json/GetUrlSubmissionQuota*' => Http::response(['d' => ['DailyQuota' => 10, 'MonthlyQuota' => 300]]),
            'ssl.bing.com/webmaster/api.svc/json/SubmitUrlBatch*' => Http::response(['d' => null]),
        ]);

        PulsoServer::tool(SubmitUrlsToBingTool::class, [
            'property_id' => $this->property->id,
            'urls' => ['https://www.example.com/imu/', 'https://example.com/tasse/'],
        ])
            ->assertOk()
            ->assertSee('"submitted":2,"remaining_today":8');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'SubmitUrlBatch')
            && $request['siteUrl'] === 'https://www.example.com/'
            && $request['urlList'] === ['https://www.example.com/imu/', 'https://example.com/tasse/']);
    });

    it('refuses urls of another site', function () {
        PulsoServer::tool(SubmitUrlsToBingTool::class, [
            'property_id' => $this->property->id,
            'urls' => ['https://other.test/page'],
        ])->assertHasErrors(['https://other.test/page']);

        Http::assertNothingSent();
    });

    it('refuses to go over the daily quota', function () {
        Http::fake(['ssl.bing.com/webmaster/api.svc/json/GetUrlSubmissionQuota*' => Http::response(['d' => ['DailyQuota' => 1, 'MonthlyQuota' => 5]])]);

        PulsoServer::tool(SubmitUrlsToBingTool::class, [
            'property_id' => $this->property->id,
            'urls' => ['https://www.example.com/a', 'https://www.example.com/b'],
        ])->assertHasErrors(['Quota Bing insufficiente']);

        Http::assertSentCount(1);
    });
});

it('cannot read bing data of another users property', function () {
    $other = GaProperty::factory()->create(['bing_site_url' => 'https://other.test/']);

    expect(fn () => PulsoServer::tool(GetPropertyBingSearchTool::class, ['property_id' => $other->id]))
        ->toThrow(ModelNotFoundException::class);
});

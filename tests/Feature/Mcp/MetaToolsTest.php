<?php

use App\Mcp\Servers\PulsoServer;
use App\Mcp\Tools\GetMetaAdsTool;
use App\Mcp\Tools\GetMetaBreakdownTool;
use App\Mcp\Tools\GetMetaCampaignsTool;
use App\Mcp\Tools\ListMetaAdAccountsTool;
use App\Models\User;
use App\Services\MetaAdsService;
use App\Services\SettingService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-11 10:00:00');

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    app(SettingService::class)->set($this->user->id, MetaAdsService::SETTING_KEY, 'meta-token', 'meta', encrypted: true);
});

/**
 * One Insights row as the Marketing API returns it (numbers as strings).
 *
 * @param  array<string, string>  $attributes
 * @param  array<string, float>  $actions
 * @param  array<string, float>  $values
 * @return array<string, mixed>
 */
function metaRow(array $attributes, float $spend, int $impressions, int $linkClicks, array $actions = [], array $values = []): array
{
    return $attributes + [
        'account_currency' => 'EUR',
        'spend' => (string) $spend,
        'impressions' => (string) $impressions,
        'reach' => (string) intdiv($impressions, 2),
        'frequency' => '2.0',
        'inline_link_clicks' => (string) $linkClicks,
        'actions' => collect($actions)->map(fn ($value, $type) => ['action_type' => $type, 'value' => (string) $value])->values()->all(),
        'action_values' => collect($values)->map(fn ($value, $type) => ['action_type' => $type, 'value' => (string) $value])->values()->all(),
    ];
}

/**
 * @param  Closure(array<string, mixed>): array  $insights  rows for the query of a request
 */
function fakeMeta(Closure $insights, array $accounts = []): void
{
    Http::fake([
        'graph.facebook.com/v27.0/me/adaccounts*' => Http::response(['data' => $accounts]),
        'graph.facebook.com/v27.0/act_*/insights*' => fn (Request $request) => Http::response(['data' => $insights($request->data())]),
    ]);
}

it('lists the ad accounts with readable status', function () {
    fakeMeta(fn () => [], [
        ['account_id' => '123', 'id' => 'act_123', 'name' => 'Calcolato', 'account_status' => 1, 'currency' => 'EUR', 'timezone_name' => 'Europe/Rome'],
    ]);

    PulsoServer::tool(ListMetaAdAccountsTool::class, [])
        ->assertOk()
        ->assertSee('["123","Calcolato","ACTIVE","EUR","Europe\/Rome"]');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer meta-token'));
});

it('returns campaigns with the best conversion action and the previous period', function () {
    fakeMeta(function (array $query): array {
        $range = json_decode($query['time_range'], true);
        $current = $range['since'] === '2026-10-12' && $range['until'] === '2026-11-10';

        return match (true) {
            $query['level'] === 'account' && $current => [metaRow([], 100, 10000, 200, ['link_click' => 250, 'omni_purchase' => 5, 'purchase' => 5], ['omni_purchase' => 400])],
            $query['level'] === 'account' => [metaRow([], 50, 5000, 100, ['omni_purchase' => 2])],
            default => [
                metaRow(['campaign_id' => '1', 'campaign_name' => 'Retargeting', 'objective' => 'OUTCOME_SALES'], 30, 3000, 90, ['omni_purchase' => 4], ['omni_purchase' => 320]),
                metaRow(['campaign_id' => '2', 'campaign_name' => 'Prospecting', 'objective' => 'OUTCOME_SALES'], 70, 7000, 110, ['omni_purchase' => 1], ['omni_purchase' => 80]),
            ],
        };
    }, [['account_id' => '123', 'name' => 'Calcolato', 'account_status' => 1, 'currency' => 'EUR']]);

    PulsoServer::tool(GetMetaCampaignsTool::class, [])
        ->assertOk()
        ->assertSee('"account_id":"123","currency":"EUR"')
        ->assertSee('"conversion_action":"omni_purchase"')
        ->assertSee('"available_actions":{"link_click":250')
        ->assertSee('"change_pct":{"cost":100,"conversions":150,"cpa":-20}')
        ->assertSee('["2","Prospecting","OUTCOME_SALES",70,3500,7000,2,110,1.57,0.64,10,1,80,70,1.14]');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'act_123/insights')
        && ($request->data()['use_unified_attribution_setting'] ?? null) === 'true');
});

it('lets the agent choose the conversion action', function () {
    fakeMeta(fn () => [metaRow(['campaign_id' => '1', 'campaign_name' => 'App', 'objective' => 'OUTCOME_APP_PROMOTION'], 20, 2000, 40, ['mobile_app_install' => 10, 'omni_purchase' => 1])]);

    PulsoServer::tool(GetMetaCampaignsTool::class, ['account_id' => 'act_456', 'conversion_action' => 'mobile_app_install'])
        ->assertOk()
        ->assertSee('"conversion_action":"mobile_app_install"')
        ->assertSee('"cpa":2');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'act_456/insights'));
});

it('compares ads within a campaign', function () {
    fakeMeta(fn () => [
        metaRow(['campaign_name' => 'C', 'adset_name' => 'S', 'ad_id' => '9', 'ad_name' => 'Video'], 10, 1000, 30, ['lead' => 5]),
        metaRow(['campaign_name' => 'C', 'adset_name' => 'S', 'ad_id' => '8', 'ad_name' => 'Carousel'], 15, 1000, 10, ['lead' => 1]),
    ]);

    PulsoServer::tool(GetMetaAdsTool::class, ['account_id' => '123', 'campaign_id' => '77', 'sort_by' => 'cpa'])
        ->assertOk()
        ->assertSee('"conversion_action":"lead"')
        ->assertSee('"rows":[["C","S","9","Video",10');

    Http::assertSent(fn (Request $request): bool => ($request->data()['level'] ?? null) === 'ad'
        && str_contains($request->data()['filtering'] ?? '', '"value":"77"'));
});

it('splits results by placement', function () {
    fakeMeta(fn () => [
        metaRow(['publisher_platform' => 'instagram', 'platform_position' => 'story'], 40, 4000, 20, ['lead' => 2]),
        metaRow(['publisher_platform' => 'facebook', 'platform_position' => 'feed'], 60, 5000, 50, ['lead' => 6]),
    ]);

    PulsoServer::tool(GetMetaBreakdownTool::class, ['account_id' => '123', 'breakdown' => 'placement'])
        ->assertOk()
        ->assertSee('"cols":["publisher_platform","platform_position","cost"')
        ->assertSee('"rows":[["facebook","feed",60');

    Http::assertSent(fn (Request $request): bool => ($request->data()['breakdowns'] ?? null) === 'publisher_platform,platform_position');
});

it('asks for an account when the token reads several', function () {
    fakeMeta(fn () => [], [
        ['account_id' => '1', 'name' => 'A', 'account_status' => 1],
        ['account_id' => '2', 'name' => 'B', 'account_status' => 1],
    ]);

    PulsoServer::tool(GetMetaCampaignsTool::class, [])
        ->assertHasErrors(['Più account pubblicitari Meta']);
});

it('flags an expired token for the health page', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'Error validating access token']], 400)]);

    PulsoServer::tool(ListMetaAdAccountsTool::class, [])
        ->assertHasErrors(['Il token Meta non è più valido']);

    expect(app(SettingService::class)->get($this->user->id, MetaAdsService::INVALID_SETTING_KEY))->toBe('1');
});

it('explains how to configure meta without a token', function () {
    app(SettingService::class)->set($this->user->id, MetaAdsService::SETTING_KEY, null, 'meta');

    PulsoServer::tool(ListMetaAdAccountsTool::class, [])
        ->assertHasErrors(['Impostazioni → Meta']);
});

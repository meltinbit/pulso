<?php

use App\Mcp\Servers\PulsoServer;
use App\Mcp\Tools\GetAiVisibilityTool;
use App\Mcp\Tools\SetAiVisibilityPromptsTool;
use App\Models\AiVisibilityCheck;
use App\Models\AiVisibilityPrompt;
use App\Models\GaProperty;
use App\Models\PropertyBingSearchStat;
use App\Models\PropertyBingTraffic;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->property = GaProperty::factory()->for($this->user)->create(['website_url' => 'https://calcolato.it']);
});

it('adds, deduplicates and removes prompts and sets brand terms', function () {
    PulsoServer::tool(SetAiVisibilityPromptsTool::class, [
        'property_id' => $this->property->id,
        'add' => ['miglior calcolatore forfettario', ' miglior  calcolatore forfettario ', 'simulatore irpef 2026'],
        'brand_terms' => ['Calcolato'],
    ])
        ->assertOk()
        ->assertSee('"added":2')
        ->assertSee('"brand_terms":["Calcolato"]')
        ->assertSee('"cap_eur":3');

    $prompt = AiVisibilityPrompt::where('prompt', 'simulatore irpef 2026')->sole();

    PulsoServer::tool(SetAiVisibilityPromptsTool::class, ['property_id' => $this->property->id, 'remove' => [$prompt->id]])
        ->assertOk()
        ->assertDontSee('simulatore irpef');

    expect($prompt->fresh()->is_active)->toBeFalse()
        ->and(AiVisibilityPrompt::count())->toBe(2);

    PulsoServer::tool(SetAiVisibilityPromptsTool::class, ['property_id' => $this->property->id, 'add' => ['simulatore irpef 2026']])
        ->assertSee('"added":1');

    expect(AiVisibilityPrompt::count())->toBe(2);
});

it('caps the active prompts per site', function () {
    AiVisibilityPrompt::factory()->count(30)->for($this->property, 'gaProperty')->create();

    PulsoServer::tool(SetAiVisibilityPromptsTool::class, ['property_id' => $this->property->id, 'add' => ['un prompt in più']])
        ->assertHasErrors(['Massimo 30 prompt attivi']);
});

it('returns the trend, the changes since the previous run and the competitors', function () {
    [$gained, $lost, $kept] = AiVisibilityPrompt::factory()->count(3)->for($this->property, 'gaProperty')->create();
    $new = AiVisibilityPrompt::factory()->for($this->property, 'gaProperty')->create();

    $check = fn (AiVisibilityPrompt $prompt, string $date, bool $mentioned, array $domains) => AiVisibilityCheck::factory()
        ->for($prompt, 'prompt')
        ->when($mentioned, fn ($factory) => $factory->mentioned(2, 'https://calcolato.it/a/'))
        ->create(['ga_property_id' => $this->property->id, 'run_date' => $date, 'cited_domains' => $domains]);

    $check($gained, '2026-11-09', false, ['fiscoetasse.com']);
    $check($lost, '2026-11-09', true, ['calcolato.it']);
    $check($kept, '2026-11-09', true, ['calcolato.it']);
    $check($gained, '2026-11-16', true, ['fiscoetasse.com', 'calcolato.it']);
    $check($lost, '2026-11-16', false, ['fiscoetasse.com', 'money.it']);
    $check($kept, '2026-11-16', true, ['www.calcolato.it']);
    $check($new, '2026-11-16', false, ['fiscoetasse.com']);

    PulsoServer::tool(GetAiVisibilityTool::class, ['property_id' => $this->property->id])
        ->assertOk()
        ->assertSee('"latest_run":"2026-11-16"')
        ->assertSee('["2026-11-09",3,2,66.7,2]')
        ->assertSee('["2026-11-16",4,2,50,2]')
        ->assertSee('"gained",true,2,"https:\/\/calcolato.it\/a\/",null,null,null]')
        ->assertSee('"lost",false,null,null,null,null,null]')
        ->assertSee('"kept",true')
        ->assertSee('"new",false')
        ->assertSee('"competitors_latest_run":{"fiscoetasse.com":3,"money.it":1}')
        ->assertSee('"bing_trend":"not linked to Bing"');
});

it('pairs each prompt with the closest bing query and the bing trend', function () {
    Carbon::setTestNow('2026-11-11 10:00:00');
    $this->property->update(['bing_site_url' => 'https://calcolato.it/']);

    $forfettario = AiVisibilityPrompt::factory()->for($this->property, 'gaProperty')->create(['prompt' => 'Qual è il miglior calcolatore del regime forfettario?']);
    $irpef = AiVisibilityPrompt::factory()->for($this->property, 'gaProperty')->create(['prompt' => 'Come si calcola l\'IRPEF sulla tredicesima?']);
    $unmatched = AiVisibilityPrompt::factory()->for($this->property, 'gaProperty')->create(['prompt' => 'Mutuo prima casa under 36']);

    foreach ([
        ['calcolatori regime forfettario', '2026-10-30', 120, 8.0],
        ['calcolatori regime forfettario', '2026-11-06', 80, 6.0],
        ['regime forfettario', '2026-11-06', 500, 15.0],
        ['irpef tredicesima', '2026-11-06', 40, 3.0],
        ['calcolo forfettario', '2026-06-05', 999, 1.0],
    ] as [$query, $week, $impressions, $position]) {
        PropertyBingSearchStat::create(['ga_property_id' => $this->property->id, 'dimension' => 'query', 'week_start' => $week, 'value' => $query, 'clicks' => 1, 'impressions' => $impressions, 'avg_impression_position' => $position]);
    }

    foreach (['2026-11-01' => 100, '2026-10-01' => 50] as $date => $impressions) {
        PropertyBingTraffic::create(['ga_property_id' => $this->property->id, 'date' => $date, 'clicks' => 2, 'impressions' => $impressions]);
    }

    PulsoServer::tool(GetAiVisibilityTool::class, ['property_id' => $this->property->id])
        ->assertOk()
        ->assertSee('"calcolatori regime forfettario",200,7.2]')
        ->assertSee('"irpef tredicesima",40,3]')
        ->assertSee('"Mutuo prima casa under 36","not_checked",null,null,null,null,null,null]')
        ->assertSee('"bing_trend":{"to":"2026-11-01","clicks":2,"impressions":100,"previous_clicks":2,"previous_impressions":50,"impressions_change_pct":100}');
});

it('returns the latest answers of one prompt', function () {
    $prompt = AiVisibilityPrompt::factory()->for($this->property, 'gaProperty')->create();
    AiVisibilityCheck::factory()->for($prompt, 'prompt')->create(['ga_property_id' => $this->property->id, 'answer_excerpt' => 'Ti consiglio fiscoetasse.']);

    PulsoServer::tool(GetAiVisibilityTool::class, ['property_id' => $this->property->id, 'prompt_id' => $prompt->id])
        ->assertOk()
        ->assertSee('Ti consiglio fiscoetasse.');
});

it('does not expose another user\'s property', function () {
    $other = GaProperty::factory()->create();

    expect(fn () => PulsoServer::tool(GetAiVisibilityTool::class, ['property_id' => $other->id]))
        ->toThrow(ModelNotFoundException::class);
});

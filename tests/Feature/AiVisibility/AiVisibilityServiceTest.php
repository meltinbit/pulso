<?php

use App\Models\AiVisibilityCheck;
use App\Models\AiVisibilityPrompt;
use App\Models\GaProperty;
use App\Models\User;
use App\Services\AiVisibilityService;
use App\Services\SettingService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-11-16 07:00:00');

    $this->user = User::factory()->create();
    $this->settings = app(SettingService::class);
    $this->settings->set($this->user->id, AiVisibilityService::API_KEY_SETTING, 'sk-test', 'ai_visibility', encrypted: true);
    $this->property = GaProperty::factory()->for($this->user)->create([
        'website_url' => 'https://www.calcolato.it',
        'ai_brand_terms' => ['Calcolato'],
    ]);
});

/**
 * A Responses API answer with one search and the given cited URLs.
 *
 * @param  list<string>  $urls
 */
function openAiAnswer(string $text, array $urls = [], int $searches = 1): array
{
    return [
        'output' => array_merge(
            array_fill(0, $searches, ['type' => 'web_search_call', 'status' => 'completed', 'action' => ['type' => 'search', 'query' => 'q']]),
            [[
                'type' => 'message',
                'role' => 'assistant',
                'content' => [[
                    'type' => 'output_text',
                    'text' => $text,
                    'annotations' => array_map(fn (string $url): array => ['type' => 'url_citation', 'url' => $url, 'title' => 'T', 'start_index' => 0, 'end_index' => 1], $urls),
                ]],
            ]],
        ),
        'usage' => ['input_tokens' => 10000, 'output_tokens' => 1000],
    ];
}

it('records whether chatgpt cites the site, with position, page and cost', function () {
    $cited = AiVisibilityPrompt::factory()->for($this->property, 'gaProperty')->create(['prompt' => 'miglior calcolatore forfettario']);
    $named = AiVisibilityPrompt::factory()->for($this->property, 'gaProperty')->create(['prompt' => 'come calcolo le tasse forfettarie']);
    $missing = AiVisibilityPrompt::factory()->for($this->property, 'gaProperty')->create(['prompt' => 'simulatore irpef']);

    Http::fake(['api.openai.com/v1/responses' => function (Request $request) {
        return Http::response(match ($request['input']) {
            'miglior calcolatore forfettario' => openAiAnswer('Ecco i migliori.', [
                'https://www.fiscoetasse.com/x?utm_source=openai',
                'https://www.fiscoetasse.com/y',
                'https://calcolato.it/forfettario/?utm_source=openai',
            ]),
            'come calcolo le tasse forfettarie' => openAiAnswer('Puoi usare Calcolato per farlo.', ['https://agenziaentrate.gov.it/']),
            default => openAiAnswer('Usa il simulatore ufficiale.', ['https://agenziaentrate.gov.it/'], searches: 2),
        });
    }]);

    $result = app(AiVisibilityService::class)->run($this->user);

    expect($result)->toMatchArray(['status' => 'ok', 'checked' => 3, 'errors' => 0]);

    $check = AiVisibilityCheck::where('ai_visibility_prompt_id', $cited->id)->sole();
    expect($check->mentioned)->toBeTrue()
        ->and($check->position)->toBe(2)
        ->and($check->cited_url)->toBe('https://calcolato.it/forfettario/')
        ->and($check->cited_domains)->toBe(['fiscoetasse.com', 'calcolato.it'])
        ->and($check->cost_usd)->toBe(0.0132);

    expect(AiVisibilityCheck::where('ai_visibility_prompt_id', $named->id)->sole())
        ->mentioned->toBeTrue()->position->toBeNull();

    expect(AiVisibilityCheck::where('ai_visibility_prompt_id', $missing->id)->sole())
        ->mentioned->toBeFalse()->searches->toBe(2)->cost_usd->toBe(0.0232);

    Http::assertSent(fn (Request $request): bool => $request['model'] === 'gpt-5.6-luna'
        && $request['tools'][0]['type'] === 'web_search'
        && $request['tools'][0]['user_location']['country'] === 'IT'
        && $request['store'] === false
        && $request->hasHeader('Authorization', 'Bearer sk-test'));

    expect(app(AiVisibilityService::class)->run($this->user)['checked'])->toBe(0);
});

it('stops before the monthly cap', function () {
    $this->settings->set($this->user->id, AiVisibilityService::CAP_SETTING, '0.05', 'ai_visibility');
    AiVisibilityPrompt::factory()->count(4)->for($this->property, 'gaProperty')->create();
    Http::fake(['api.openai.com/v1/responses' => Http::response(openAiAnswer('Nessuna fonte.', [], searches: 1))]);

    $result = app(AiVisibilityService::class)->run($this->user);

    expect($result['status'])->toBe('budget_reached')
        ->and($result['checked'])->toBe(2)
        ->and($result['skipped_for_budget'])->toBe(2)
        ->and(AiVisibilityCheck::sum('cost_usd'))->toBeLessThanOrEqual(0.05);
});

it('stops and flags the key when openai rejects it', function () {
    AiVisibilityPrompt::factory()->count(2)->for($this->property, 'gaProperty')->create();
    Http::fake(['api.openai.com/v1/responses' => Http::response(['error' => ['message' => 'Incorrect API key']], 401)]);

    $result = app(AiVisibilityService::class)->run($this->user);

    expect($result['status'])->toBe('api_key_error')
        ->and(AiVisibilityCheck::count())->toBe(0)
        ->and($this->settings->get($this->user->id, AiVisibilityService::KEY_INVALID_SETTING))->toBe('1');

    Http::assertSentCount(1);
});

it('respects the frequency setting unless forced', function () {
    AiVisibilityPrompt::factory()->for($this->property, 'gaProperty')->create();
    Http::fake(['api.openai.com/v1/responses' => Http::response(openAiAnswer('x'))]);
    $service = app(AiVisibilityService::class);

    $this->settings->set($this->user->id, AiVisibilityService::FREQUENCY_SETTING, 'off', 'ai_visibility');
    expect($service->run($this->user)['status'])->toBe('not_due');

    $this->settings->set($this->user->id, AiVisibilityService::FREQUENCY_SETTING, 'biweekly', 'ai_visibility');
    AiVisibilityCheck::factory()->for(AiVisibilityPrompt::first(), 'prompt')->create(['ga_property_id' => $this->property->id, 'run_date' => '2026-11-09']);
    expect($service->run($this->user)['status'])->toBe('not_due')
        ->and($service->run($this->user, force: true)['checked'])->toBe(1);
});

it('does nothing without an api key', function () {
    $this->settings->set($this->user->id, AiVisibilityService::API_KEY_SETTING, null, 'ai_visibility');
    AiVisibilityPrompt::factory()->for($this->property, 'gaProperty')->create();

    expect(app(AiVisibilityService::class)->run($this->user)['status'])->toBe('no_api_key');
    Http::assertNothingSent();
});

it('runs from the artisan command', function () {
    AiVisibilityPrompt::factory()->for($this->property, 'gaProperty')->create();
    Http::fake(['api.openai.com/v1/responses' => Http::response(openAiAnswer('calcolato.it è ottimo'))]);

    $this->artisan('pulso:ai-visibility', ['--force' => true])
        ->expectsOutputToContain('ok, 1 checked')
        ->assertSuccessful();
});

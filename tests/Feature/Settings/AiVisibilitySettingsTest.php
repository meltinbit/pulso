<?php

use App\Jobs\RunAiVisibilityChecks;
use App\Models\User;
use App\Services\AiVisibilityService;
use App\Services\SettingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->settings = app(SettingService::class);
});

it('verifies and saves the key with model, cap and frequency', function () {
    Http::fake(['api.openai.com/v1/models' => Http::response(['data' => []])]);

    $this->actingAs($this->user)
        ->put(route('settings.ai-visibility.update'), [
            'openai_api_key' => 'sk-good',
            'model' => 'gpt-5.6-luna',
            'monthly_cap_eur' => '3',
            'frequency' => 'biweekly',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $service = app(AiVisibilityService::class);

    expect($service->apiKey($this->user))->toBe('sk-good')
        ->and($service->monthlyCapEur($this->user))->toBe(3.0)
        ->and($service->frequency($this->user))->toBe('biweekly');
});

it('rejects a key openai refuses', function () {
    Http::fake(['api.openai.com/v1/models' => Http::response(['error' => ['message' => 'Incorrect API key']], 401)]);

    $this->actingAs($this->user)
        ->put(route('settings.ai-visibility.update'), ['openai_api_key' => 'sk-bad', 'model' => 'gpt-5.6-luna', 'monthly_cap_eur' => '3', 'frequency' => 'weekly'])
        ->assertSessionHasErrors('openai_api_key');

    expect(app(AiVisibilityService::class)->apiKey($this->user))->toBeNull();
});

it('shows the defaults without exposing the key and runs on demand', function () {
    $this->settings->set($this->user->id, AiVisibilityService::API_KEY_SETTING, 'sk-secret', 'ai_visibility', encrypted: true);
    Queue::fake();

    $this->actingAs($this->user)->get(route('settings.ai-visibility'))
        ->assertOk()
        ->assertDontSee('sk-secret')
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/ai-visibility')
            ->where('hasKey', true)
            ->where('model', 'gpt-5.6-luna')
            ->where('capEur', 3)
            ->where('frequency', 'weekly'));

    $this->actingAs($this->user)->post(route('settings.ai-visibility.run'))->assertRedirect();

    Queue::assertPushed(RunAiVisibilityChecks::class, fn (RunAiVisibilityChecks $job): bool => $job->force);
});

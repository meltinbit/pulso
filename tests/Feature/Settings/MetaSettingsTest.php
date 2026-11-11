<?php

use App\Models\AppSetting;
use App\Models\User;
use App\Services\MetaAdsService;
use App\Services\SettingService;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
});

it('saves a valid token encrypted and picks the only account as default', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => [['account_id' => '123', 'name' => 'Calcolato', 'account_status' => 1]]])]);

    $this->actingAs($this->user)
        ->put(route('settings.meta.update'), ['meta_access_token' => 'good-token'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $stored = AppSetting::where('user_id', $this->user->id)->where('key', MetaAdsService::SETTING_KEY)->sole();
    $settings = app(SettingService::class);

    expect($stored->is_encrypted)->toBeTrue()
        ->and($stored->value)->not->toBe('good-token')
        ->and($settings->get($this->user->id, MetaAdsService::SETTING_KEY))->toBe('good-token')
        ->and($settings->get($this->user->id, MetaAdsService::DEFAULT_ACCOUNT_SETTING_KEY))->toBe('123');
});

it('rejects an invalid token with a readable message', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token']], 400)]);

    $this->actingAs($this->user)
        ->put(route('settings.meta.update'), ['meta_access_token' => 'bad-token'])
        ->assertSessionHasErrors('meta_access_token');

    expect(app(SettingService::class)->get($this->user->id, MetaAdsService::SETTING_KEY))->toBeNull();
});

it('shows the ad accounts without exposing the token and saves the default', function () {
    app(SettingService::class)->set($this->user->id, MetaAdsService::SETTING_KEY, 'secret-token', 'meta', encrypted: true);
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
        ['account_id' => '1', 'name' => 'A', 'account_status' => 1, 'currency' => 'EUR'],
        ['account_id' => '2', 'name' => 'B', 'account_status' => 2, 'currency' => 'EUR'],
    ]])]);

    $this->actingAs($this->user)->get(route('settings.meta'))
        ->assertOk()
        ->assertDontSee('secret-token')
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/meta')
            ->where('hasToken', true)
            ->has('accounts', 2)
            ->where('accounts.1.status', 'DISABLED'));

    $this->actingAs($this->user)
        ->put(route('settings.meta.account'), ['account_id' => 'act_2'])
        ->assertRedirect();

    expect(app(SettingService::class)->get($this->user->id, MetaAdsService::DEFAULT_ACCOUNT_SETTING_KEY))->toBe('2');
});

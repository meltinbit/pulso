<?php

use App\Jobs\SyncBingWebmasterData;
use App\Models\AppSetting;
use App\Models\GaProperty;
use App\Models\User;
use App\Services\BingSyncService;
use App\Services\SettingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
});

it('saves a valid api key encrypted', function () {
    Http::fake(['ssl.bing.com/*' => Http::response(['d' => []])]);

    $this->actingAs($this->user)
        ->put(route('settings.bing.update'), ['bing_api_key' => 'good-key'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $stored = AppSetting::where('user_id', $this->user->id)->where('key', BingSyncService::SETTING_KEY)->sole();

    expect($stored->is_encrypted)->toBeTrue()
        ->and($stored->value)->not->toBe('good-key')
        ->and(app(SettingService::class)->get($this->user->id, BingSyncService::SETTING_KEY))->toBe('good-key');
});

it('rejects an invalid api key with a readable message', function () {
    Http::fake(['ssl.bing.com/*' => Http::response(['Message' => 'ERROR!!! InvalidApiKey'], 400)]);

    $this->actingAs($this->user)
        ->put(route('settings.bing.update'), ['bing_api_key' => 'bad-key'])
        ->assertSessionHasErrors(['bing_api_key' => 'La API key di Bing non è valida o è stata revocata: generane una nuova in Bing Webmaster Tools (Impostazioni → Accesso API) e salvala in Impostazioni → Bing.']);

    expect(app(SettingService::class)->get($this->user->id, BingSyncService::SETTING_KEY))->toBeNull();
});

it('shows bing sites with linked properties and never exposes the key', function () {
    app(SettingService::class)->set($this->user->id, BingSyncService::SETTING_KEY, 'secret-key', 'bing', encrypted: true);
    $property = GaProperty::factory()->for($this->user)->create(['website_url' => 'https://example.com']);

    Http::fake(['ssl.bing.com/*' => Http::response(['d' => [['Url' => 'https://example.com/', 'IsVerified' => true]]])]);

    $this->actingAs($this->user)->get(route('settings.bing'))
        ->assertOk()
        ->assertDontSee('secret-key')
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/bing')
            ->where('hasKey', true)
            ->where('sites.0.property_id', $property->id)
            ->where('properties.0.bing_site_url', 'https://example.com/'));
});

it('links a property to a bing site by hand, only for its owner', function () {
    $property = GaProperty::factory()->for($this->user)->create();
    $other = GaProperty::factory()->create();

    $this->actingAs($this->user)
        ->put(route('settings.bing.property', $property), ['bing_site_url' => 'https://example.com/'])
        ->assertRedirect();

    $this->actingAs($this->user)
        ->put(route('settings.bing.property', $other), ['bing_site_url' => 'https://example.com/'])
        ->assertForbidden();

    expect($property->fresh()->bing_site_url)->toBe('https://example.com/')
        ->and($other->fresh()->bing_site_url)->toBeNull();
});

it('starts a bing sync', function () {
    Queue::fake();

    $this->actingAs($this->user)->post(route('settings.bing.sync'))->assertRedirect();

    Queue::assertPushed(SyncBingWebmasterData::class);
});

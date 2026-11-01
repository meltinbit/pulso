<?php

use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    configureGoogleAds();
});

test('exchanges the pasted authorization code for a refresh token', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'refresh_token' => '1//new-refresh-token']),
    ]);

    $this->artisan('pulso:google-ads-auth')
        ->expectsQuestion('URL di redirect (o solo il codice)', 'auth-code-123')
        ->expectsOutputToContain('GOOGLE_ADS_REFRESH_TOKEN=1//new-refresh-token')
        ->assertSuccessful();

    Http::assertSent(fn ($request) => $request['code'] === 'auth-code-123'
        && $request['grant_type'] === 'authorization_code'
        && $request['redirect_uri'] === 'http://127.0.0.1:8765');
});

test('rejects a redirect url with a different state', function () {
    $this->artisan('pulso:google-ads-auth')
        ->expectsQuestion('URL di redirect (o solo il codice)', 'http://127.0.0.1:8765/?state=forged&code=abc&scope=adwords')
        ->expectsOutputToContain('state non corrisponde')
        ->assertFailed();

    Http::assertNothingSent();
});

test('fails clearly when the OAuth client is missing', function () {
    configureGoogleAds(['client_id' => null]);

    $this->artisan('pulso:google-ads-auth')
        ->expectsOutputToContain('GOOGLE_ADS_CLIENT_ID')
        ->assertFailed();
});

test('refuses to run without an interactive terminal', function () {
    $this->artisan('pulso:google-ads-auth', ['--no-interaction' => true])
        ->expectsOutputToContain('terminale interattivo')
        ->assertFailed();

    Http::assertNothingSent();
});

test('reports a failed code exchange', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Bad Request'], 400),
    ]);

    $this->artisan('pulso:google-ads-auth')
        ->expectsQuestion('URL di redirect (o solo il codice)', 'used-code')
        ->expectsOutputToContain('Scambio del codice fallito: Bad Request')
        ->assertFailed();
});

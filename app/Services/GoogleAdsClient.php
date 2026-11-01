<?php

namespace App\Services;

use App\Exceptions\GoogleAdsApiException;
use App\Models\GoogleAdsDailyUsage;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin REST client for the Google Ads API, authenticated with the single set
 * of credentials stored in config/services.php. It keeps a daily operations
 * counter so keyword research stays within the developer token quota.
 */
class GoogleAdsClient
{
    private const ACCESS_TOKEN_CACHE_KEY = 'google_ads.access_token';

    /**
     * Readable messages for the error codes Google Ads returns most often.
     * Codes are matched by value regardless of their error category.
     *
     * @var array<string, string>
     */
    private const ERROR_MESSAGES = [
        'DEVELOPER_TOKEN_NOT_APPROVED' => 'Accesso API solo per account di test: richiedi il livello Explorer o Basic in Google Cloud Console (API e servizi → Google Ads API → Livelli di accesso) e attendi l\'approvazione.',
        'DEVELOPER_TOKEN_PROHIBITED' => 'Il developer token non può essere usato con questo progetto Google Cloud o account.',
        'DEVELOPER_TOKEN_INVALID' => 'Il developer token non è valido: controlla GOOGLE_ADS_DEVELOPER_TOKEN.',
        'DEVELOPER_TOKEN_PARAMETER_MISSING' => 'Developer token mancante: imposta GOOGLE_ADS_DEVELOPER_TOKEN.',
        'USER_PERMISSION_DENIED' => 'L\'utente del refresh token non ha accesso a questo account: verifica GOOGLE_ADS_LOGIN_CUSTOMER_ID (MCC) e GOOGLE_ADS_CUSTOMER_ID.',
        'ACTION_NOT_PERMITTED' => 'L\'account non ha il permesso di usare la ricerca keyword (Keyword Planner).',
        'CUSTOMER_NOT_ENABLED' => 'L\'account Google Ads indicato in GOOGLE_ADS_CUSTOMER_ID non è attivo.',
        'CUSTOMER_NOT_FOUND' => 'Customer ID non trovato: controlla GOOGLE_ADS_CUSTOMER_ID (solo cifre, senza trattini).',
        'INVALID_CUSTOMER_ID' => 'Customer ID non valido: controlla GOOGLE_ADS_CUSTOMER_ID (solo cifre, senza trattini).',
        'INVALID_LOGIN_CUSTOMER_ID' => 'Login customer ID non valido: controlla GOOGLE_ADS_LOGIN_CUSTOMER_ID (ID dell\'MCC, solo cifre).',
        'OAUTH_TOKEN_INVALID' => 'Il token OAuth non è valido: rigenera il refresh token con php artisan pulso:google-ads-auth.',
        'OAUTH_TOKEN_EXPIRED' => 'Il token OAuth è scaduto: rigenera il refresh token con php artisan pulso:google-ads-auth.',
        'NOT_ADS_USER' => 'L\'account Google del refresh token non è associato a nessun account Google Ads.',
        'RESOURCE_EXHAUSTED' => 'Quota Google Ads API esaurita per oggi: riprova domani.',
        'RESOURCE_TEMPORARILY_EXHAUSTED' => 'Troppe richieste a Google Ads API in poco tempo: riprova tra qualche minuto.',
    ];

    /**
     * Send a POST request to a Google Ads API method, for example
     * `customers/123:generateKeywordIdeas` or `geoTargetConstants:suggest`.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws GoogleAdsApiException
     */
    public function post(string $method, array $body): array
    {
        $this->ensureConfigured();
        $this->ensureWithinDailyLimit();

        $headers = ['developer-token' => (string) config('services.google_ads.developer_token')];

        if ($loginCustomerId = $this->digits(config('services.google_ads.login_customer_id'))) {
            $headers['login-customer-id'] = $loginCustomerId;
        }

        $this->recordOperation();

        $response = Http::withToken($this->accessToken())
            ->withHeaders($headers)
            ->acceptJson()
            ->timeout(60)
            ->connectTimeout(5)
            ->retry(2, 1000, fn (Throwable $e): bool => $e instanceof RequestException && $e->response->serverError(), throw: false)
            ->post($this->baseUrl().'/'.$method, $body);

        if ($response->failed()) {
            throw $this->translateError($response);
        }

        return $response->json() ?? [];
    }

    /**
     * The customer ID keyword planning requests are made against.
     */
    public function customerId(): string
    {
        return $this->digits(config('services.google_ads.customer_id')) ?? '';
    }

    public function isConfigured(): bool
    {
        return $this->missingSettings() === [];
    }

    public function operationsToday(): int
    {
        return (int) GoogleAdsDailyUsage::where('date', now()->toDateString())->value('operations');
    }

    public function dailyLimit(): int
    {
        return (int) config('services.google_ads.daily_operations_limit', 1000);
    }

    /**
     * @return array<int, string>
     */
    private function missingSettings(): array
    {
        $required = [
            'developer_token' => 'GOOGLE_ADS_DEVELOPER_TOKEN',
            'customer_id' => 'GOOGLE_ADS_CUSTOMER_ID',
            'client_id' => 'GOOGLE_ADS_CLIENT_ID',
            'client_secret' => 'GOOGLE_ADS_CLIENT_SECRET',
            'refresh_token' => 'GOOGLE_ADS_REFRESH_TOKEN',
        ];

        return array_values(array_filter(
            $required,
            fn (string $env, string $key): bool => blank(config("services.google_ads.{$key}")),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    private function ensureConfigured(): void
    {
        $missing = $this->missingSettings();

        if ($missing !== []) {
            throw new GoogleAdsApiException('Google Ads non è configurato: mancano '.implode(', ', $missing).' nel file .env.', 'NOT_CONFIGURED');
        }
    }

    private function ensureWithinDailyLimit(): void
    {
        if ($this->operationsToday() >= $this->dailyLimit()) {
            throw new GoogleAdsApiException(
                "Limite giornaliero di {$this->dailyLimit()} operazioni Google Ads raggiunto (GOOGLE_ADS_DAILY_OPERATIONS_LIMIT). I risultati già in cache restano disponibili.",
                'DAILY_LIMIT_REACHED',
            );
        }
    }

    private function recordOperation(): void
    {
        $usage = GoogleAdsDailyUsage::firstOrCreate(['date' => now()->toDateString()], ['operations' => 0]);
        $usage->increment('operations');
    }

    /**
     * Exchange the refresh token for an access token, cached until shortly before it expires.
     *
     * @throws GoogleAdsApiException
     */
    private function accessToken(): string
    {
        $cached = Cache::get(self::ACCESS_TOKEN_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()
            ->timeout(10)
            ->connectTimeout(5)
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google_ads.client_id'),
                'client_secret' => config('services.google_ads.client_secret'),
                'refresh_token' => config('services.google_ads.refresh_token'),
                'grant_type' => 'refresh_token',
            ]);

        $token = $response->json('access_token');

        if ($response->failed() || ! is_string($token)) {
            $error = (string) $response->json('error', '');

            throw new GoogleAdsApiException(match ($error) {
                'invalid_grant' => 'Il refresh token Google Ads non è più valido: rigeneralo con php artisan pulso:google-ads-auth.',
                'invalid_client' => 'Client OAuth Google Ads non valido: controlla GOOGLE_ADS_CLIENT_ID e GOOGLE_ADS_CLIENT_SECRET.',
                default => 'Impossibile ottenere un access token per Google Ads API.',
            }, $error ?: 'TOKEN_REFRESH_FAILED');
        }

        $ttl = max(60, (int) $response->json('expires_in', 3600) - 300);
        Cache::put(self::ACCESS_TOKEN_CACHE_KEY, $token, $ttl);

        return $token;
    }

    private function translateError(Response $response): GoogleAdsApiException
    {
        $codes = collect($response->json('error.details', []))
            ->flatMap(fn (mixed $detail): array => is_array($detail) ? ($detail['errors'] ?? []) : [])
            ->flatMap(fn (mixed $error): array => is_array($error) ? array_values($error['errorCode'] ?? []) : [])
            ->filter(fn (mixed $code): bool => is_string($code))
            ->values();

        Log::warning('Google Ads API error', [
            'status' => $response->status(),
            'codes' => $codes->all(),
            'body' => $response->json() ?? $response->body(),
        ]);

        $googleMessage = (string) ($response->json('error.details.0.errors.0.message') ?? '');

        if ($codes->contains('DEVELOPER_TOKEN_NOT_APPROVED') && str_contains(strtolower($googleMessage), 'explorer access')) {
            return new GoogleAdsApiException(
                'Con l\'accesso Explorer Google non consente la ricerca keyword: serve il livello Di base (Basic), da richiedere in Google Cloud Console (API e servizi → Google Ads API → Livelli di accesso) dopo la verifica del brand.',
                'EXPLORER_ACCESS_NOT_ALLOWED',
            );
        }

        foreach ($codes as $code) {
            if (isset(self::ERROR_MESSAGES[$code])) {
                return new GoogleAdsApiException(self::ERROR_MESSAGES[$code], $code);
            }
        }

        if ($response->status() === 429) {
            return new GoogleAdsApiException(self::ERROR_MESSAGES['RESOURCE_TEMPORARILY_EXHAUSTED'], 'RESOURCE_TEMPORARILY_EXHAUSTED');
        }

        $detail = $response->json('error.details.0.errors.0.message') ?? $response->json('error.message');

        return new GoogleAdsApiException(
            'Errore Google Ads API'.($detail ? ": {$detail}" : " (HTTP {$response->status()})."),
            $codes->first() ?? (string) $response->json('error.status', 'UNKNOWN'),
        );
    }

    private function baseUrl(): string
    {
        return 'https://googleads.googleapis.com/'.config('services.google_ads.api_version', 'v26');
    }

    private function digits(mixed $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return $digits === '' ? null : $digits;
    }
}

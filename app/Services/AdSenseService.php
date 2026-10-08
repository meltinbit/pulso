<?php

namespace App\Services;

use App\Exceptions\AdSenseApiException;
use App\Models\GaProperty;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AdSenseService
{
    private const BASE_URL = 'https://adsense.googleapis.com/v2';

    /** Metrics requested for every report, in this order. */
    public const METRICS = [
        'ESTIMATED_EARNINGS',
        'PAGE_VIEWS',
        'IMPRESSIONS',
        'CLICKS',
        'AD_REQUESTS',
        'MATCHED_AD_REQUESTS',
    ];

    public function __construct(
        private GoogleTokenService $tokenService,
    ) {}

    /**
     * Fetch daily AdSense metrics for the property's domain, optionally broken
     * down by one extra report dimension (e.g. COUNTRY_NAME).
     *
     * @return array<int, array{date: string, value: string, earnings: float, page_views: int, impressions: int, clicks: int, ad_requests: int, matched_ad_requests: int}>
     *
     * @throws AdSenseApiException when the account cannot be resolved or the API call fails
     */
    public function fetchDailyRows(GaProperty $property, string $startDate, string $endDate, ?string $dimension = null): array
    {
        $host = $this->propertyHost($property);

        if (! $host) {
            throw new AdSenseApiException("{$property->display_name} non ha un URL del sito: AdSense filtra i dati per dominio.");
        }

        $account = $this->resolveAccount($property);

        if (! $account) {
            throw new AdSenseApiException("Nessun account AdSense trovato per {$property->gaConnection->google_email}.");
        }

        $token = $this->tokenService->getFreshToken($property->gaConnection);

        $response = Http::withToken($token)
            ->timeout(60)
            ->connectTimeout(5)
            ->retry(3, 2000, throw: false)
            ->get(self::BASE_URL."/{$account}/reports:generate?".$this->reportQuery($property, $host, $startDate, $endDate, $dimension));

        if ($response->failed()) {
            Log::warning("AdSense API error for {$property->display_name} ({$account}): HTTP {$response->status()} {$response->body()}");
            Cache::forget($this->cacheKey($property));

            throw new AdSenseApiException("AdSense API error for {$property->display_name}: HTTP {$response->status()}");
        }

        $headers = collect($response->json('headers', []))->pluck('name')->all();

        return collect($response->json('rows', []))
            ->map(fn (array $row): array => $this->mapRow($headers, $row, $dimension))
            ->all();
    }

    /**
     * List the AdSense accounts visible to the property's Google connection.
     *
     * @return array<int, array{name: string, display_name: string|null, state: string|null}>
     *
     * @throws AdSenseApiException when the API call fails
     */
    public function listAccounts(GaProperty $property): array
    {
        $token = $this->tokenService->getFreshToken($property->gaConnection);

        $response = Http::withToken($token)
            ->timeout(15)
            ->connectTimeout(5)
            ->get(self::BASE_URL.'/accounts');

        if ($response->failed()) {
            Log::warning("AdSense accounts API error for {$property->display_name}: HTTP {$response->status()} {$response->body()}");

            throw new AdSenseApiException("AdSense accounts API error for {$property->gaConnection->google_email}: HTTP {$response->status()}");
        }

        return collect($response->json('accounts', []))
            ->map(fn (array $account): array => [
                'name' => $account['name'] ?? '',
                'display_name' => $account['displayName'] ?? null,
                'state' => $account['state'] ?? null,
            ])
            ->filter(fn (array $account): bool => filled($account['name']))
            ->values()
            ->all();
    }

    /**
     * Resolve the AdSense account ("accounts/pub-…") for the property's
     * connection, skipping closed accounts. Only a found account is cached:
     * a missing one is asked again, so access granted later (e.g. accepting
     * an AdSense user invite) is picked up on the next sync.
     *
     * @throws AdSenseApiException when the API call fails
     */
    public function resolveAccount(GaProperty $property): ?string
    {
        $cached = Cache::get($this->cacheKey($property));

        if (filled($cached)) {
            return $cached;
        }

        $account = collect($this->listAccounts($property))
            ->first(fn (array $account): bool => $account['state'] !== 'CLOSED');

        $resolved = $account['name'] ?? null;

        if ($resolved) {
            Cache::put($this->cacheKey($property), $resolved, now()->addDay());
        }

        return $resolved;
    }

    /**
     * The property's host without "www.", which is how AdSense reports domains.
     */
    public function propertyHost(GaProperty $property): ?string
    {
        $host = parse_url((string) $property->website_url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * Build the query string by hand: Google expects repeated keys
     * (`metrics=A&metrics=B`), not PHP style `metrics[0]=A`.
     */
    private function reportQuery(GaProperty $property, string $host, string $startDate, string $endDate, ?string $dimension): string
    {
        [$startYear, $startMonth, $startDay] = array_map('intval', explode('-', $startDate));
        [$endYear, $endMonth, $endDay] = array_map('intval', explode('-', $endDate));

        $params = [
            ['dateRange', 'CUSTOM'],
            ['startDate.year', $startYear],
            ['startDate.month', $startMonth],
            ['startDate.day', $startDay],
            ['endDate.year', $endYear],
            ['endDate.month', $endMonth],
            ['endDate.day', $endDay],
            ['dimensions', 'DATE'],
            ['filters', 'DOMAIN_NAME=='.$this->escapeFilter($host).',DOMAIN_NAME==www.'.$this->escapeFilter($host)],
            ['currencyCode', $property->currency ?: 'EUR'],
        ];

        if ($dimension) {
            $params[] = ['dimensions', $dimension];
        }

        foreach (self::METRICS as $metric) {
            $params[] = ['metrics', $metric];
        }

        return collect($params)
            ->map(fn (array $param): string => rawurlencode($param[0]).'='.rawurlencode((string) $param[1]))
            ->implode('&');
    }

    /**
     * Escape backslashes before commas, as the filter syntax requires.
     */
    private function escapeFilter(string $value): string
    {
        return str_replace(',', '\\,', str_replace('\\', '\\\\', $value));
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<string, mixed>  $row
     * @return array{date: string, value: string, earnings: float, page_views: int, impressions: int, clicks: int, ad_requests: int, matched_ad_requests: int}
     */
    private function mapRow(array $headers, array $row, ?string $dimension): array
    {
        $cells = collect($row['cells'] ?? [])->pluck('value')->all();
        $values = array_combine($headers, array_pad($cells, count($headers), null)) ?: [];

        return [
            'date' => (string) ($values['DATE'] ?? ''),
            'value' => $dimension ? (string) ($values[$dimension] ?? '') : '',
            'earnings' => (float) ($values['ESTIMATED_EARNINGS'] ?? 0),
            'page_views' => (int) ($values['PAGE_VIEWS'] ?? 0),
            'impressions' => (int) ($values['IMPRESSIONS'] ?? 0),
            'clicks' => (int) ($values['CLICKS'] ?? 0),
            'ad_requests' => (int) ($values['AD_REQUESTS'] ?? 0),
            'matched_ad_requests' => (int) ($values['MATCHED_AD_REQUESTS'] ?? 0),
        ];
    }

    private function cacheKey(GaProperty $property): string
    {
        return "adsense:account:{$property->ga_connection_id}";
    }
}

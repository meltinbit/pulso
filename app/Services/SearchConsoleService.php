<?php

namespace App\Services;

use App\Models\GaProperty;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SearchConsoleService
{
    public function __construct(
        private GoogleTokenService $tokenService,
    ) {}

    /**
     * Fetch top search queries for a property on a given date.
     *
     * @return array<int, array{query: string, page: string|null, clicks: int, impressions: int, ctr: float, position: float}>
     */
    public function fetchSearchQueries(GaProperty $property, string $date, int $limit = 20): array
    {
        $siteUrl = $this->resolveSiteUrl($property);

        if (! $siteUrl) {
            Log::warning("Search Console: no matching site for {$property->display_name} ({$property->website_url})");

            return [];
        }

        $token = $this->tokenService->getFreshToken($property->gaConnection);

        $response = Http::withToken($token)
            ->timeout(30)
            ->connectTimeout(5)
            ->retry(2, 1000, throw: false)
            ->post(
                "https://searchconsole.googleapis.com/webmasters/v3/sites/{$siteUrl}/searchAnalytics/query",
                [
                    'startDate' => $date,
                    'endDate' => $date,
                    'dimensions' => ['query', 'page'],
                    'rowLimit' => $limit,
                    'type' => 'web',
                ]
            );

        if ($response->failed()) {
            Log::warning("Search Console API error for {$property->display_name} ({$siteUrl}): HTTP {$response->status()} {$response->body()}");
            $this->forgetCachedSiteUrl($property);

            return [];
        }

        return collect($response->json('rows', []))->map(fn ($row) => [
            'query' => $row['keys'][0] ?? '',
            'page' => $this->extractPath($row['keys'][1] ?? null, $property->website_url),
            'clicks' => (int) ($row['clicks'] ?? 0),
            'impressions' => (int) ($row['impressions'] ?? 0),
            'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
            'position' => round($row['position'] ?? 0, 1),
        ])->all();
    }

    /**
     * List available Search Console sites for the connection.
     *
     * @return array<int, string>
     */
    public function listSites(GaProperty $property): array
    {
        $token = $this->tokenService->getFreshToken($property->gaConnection);

        $response = Http::withToken($token)
            ->timeout(15)
            ->get('https://searchconsole.googleapis.com/webmasters/v3/sites');

        if ($response->failed()) {
            return [];
        }

        return collect($response->json('siteEntry', []))
            ->pluck('siteUrl')
            ->all();
    }

    /**
     * Resolve the Search Console site identifier from the property's website_url.
     * Prefers a Domain property ("sc-domain:host"), falls back to a URL-prefix
     * site that matches the same host (with or without "www."). Result is cached
     * per property because the Search Console site list rarely changes.
     */
    private function resolveSiteUrl(GaProperty $property): ?string
    {
        $websiteUrl = $property->website_url;

        if (! $websiteUrl) {
            return null;
        }

        $host = parse_url($websiteUrl, PHP_URL_HOST);

        if (! $host) {
            return null;
        }

        $cached = Cache::get($this->cacheKey($property));

        if ($cached !== null) {
            return $cached === '' ? null : $cached;
        }

        $sites = $this->listSites($property);
        $resolved = $this->pickMatchingSite($sites, $host);

        Cache::put($this->cacheKey($property), $resolved ?? '', now()->addDay());

        return $resolved;
    }

    /**
     * @param  array<int, string>  $sites
     */
    private function pickMatchingSite(array $sites, string $host): ?string
    {
        $domainCandidate = "sc-domain:{$host}";

        if (in_array($domainCandidate, $sites, true)) {
            return urlencode($domainCandidate);
        }

        $bareHost = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        foreach ($sites as $site) {
            $siteHost = parse_url($site, PHP_URL_HOST);

            if (! $siteHost) {
                continue;
            }

            $siteBare = str_starts_with($siteHost, 'www.') ? substr($siteHost, 4) : $siteHost;

            if ($siteBare === $bareHost) {
                return urlencode($site);
            }
        }

        return null;
    }

    private function cacheKey(GaProperty $property): string
    {
        return "sc:site_url:{$property->id}";
    }

    private function forgetCachedSiteUrl(GaProperty $property): void
    {
        Cache::forget($this->cacheKey($property));
    }

    /**
     * Extract the path from a full URL, relative to the property's website.
     */
    private function extractPath(?string $fullUrl, ?string $baseUrl): ?string
    {
        if (! $fullUrl) {
            return null;
        }

        $path = parse_url($fullUrl, PHP_URL_PATH);

        return $path ?: '/';
    }
}

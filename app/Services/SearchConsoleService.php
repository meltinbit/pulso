<?php

namespace App\Services;

use App\Models\GaProperty;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;

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
     * Inspect one or more URLs and return their Google index status.
     *
     * @param  array<int, string>  $urls
     * @return array<int, array<string, mixed>>
     */
    public function inspectUrls(GaProperty $property, array $urls, string $languageCode = 'en-US'): array
    {
        $siteUrl = $this->resolveSiteUrl($property);

        if (! $siteUrl) {
            Log::warning("Search Console: no matching site for {$property->display_name} ({$property->website_url})");

            return [];
        }

        $token = $this->tokenService->getFreshToken($property->gaConnection);
        $siteIdentifier = urldecode($siteUrl);

        return collect($urls)
            ->map(fn (string $url): array => $this->inspectSingleUrl($token, $siteIdentifier, $url, $languageCode))
            ->values()
            ->all();
    }

    /**
     * List sitemaps available for the property.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listSitemaps(GaProperty $property): array
    {
        $siteUrl = $this->resolveSiteUrl($property);

        if (! $siteUrl) {
            return [];
        }

        $token = $this->tokenService->getFreshToken($property->gaConnection);

        $response = Http::withToken($token)
            ->timeout(15)
            ->get("https://searchconsole.googleapis.com/webmasters/v3/sites/{$siteUrl}/sitemaps");

        if ($response->failed()) {
            Log::warning("Search Console sitemap API error for {$property->display_name} ({$siteUrl}): HTTP {$response->status()} {$response->body()}");

            return [];
        }

        return collect($response->json('sitemap', []))
            ->map(fn (array $sitemap): array => [
                'path' => $sitemap['path'] ?? null,
                'type' => $sitemap['type'] ?? null,
                'is_pending' => (bool) ($sitemap['isPending'] ?? false),
                'is_sitemaps_index' => (bool) ($sitemap['isSitemapsIndex'] ?? false),
                'warnings' => (int) ($sitemap['warnings'] ?? 0),
                'errors' => (int) ($sitemap['errors'] ?? 0),
            ])
            ->filter(fn (array $sitemap): bool => filled($sitemap['path']))
            ->values()
            ->all();
    }

    /**
     * Discover URLs from submitted sitemaps.
     *
     * @return array<int, string>
     */
    public function discoverUrlsFromSitemaps(GaProperty $property, ?string $sitemapUrl = null, int $limit = 20): array
    {
        $sitemapUrls = $sitemapUrl
            ? [$sitemapUrl]
            : collect($this->listSitemaps($property))->pluck('path')->all();

        if ($sitemapUrls === [] && filled($property->website_url)) {
            $sitemapUrls = [rtrim($property->website_url, '/').'/sitemap.xml'];
        }

        return $this->collectUrlsFromSitemaps($sitemapUrls, $property, $limit);
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
     * @return array<string, mixed>
     */
    private function inspectSingleUrl(string $token, string $siteIdentifier, string $url, string $languageCode): array
    {
        $response = Http::withToken($token)
            ->timeout(30)
            ->connectTimeout(5)
            ->retry(2, 1000, throw: false)
            ->post('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', [
                'inspectionUrl' => $url,
                'siteUrl' => $siteIdentifier,
                'languageCode' => $languageCode,
            ]);

        if ($response->failed()) {
            return [
                'url' => $url,
                'is_indexed' => null,
                'verdict' => null,
                'coverage_state' => null,
                'indexing_state' => null,
                'robots_txt_state' => null,
                'page_fetch_state' => null,
                'last_crawl_time' => null,
                'google_canonical' => null,
                'user_canonical' => null,
                'referring_urls' => [],
                'sitemaps' => [],
                'inspection_result_link' => null,
                'error' => "HTTP {$response->status()}",
            ];
        }

        $result = $response->json('inspectionResult', []);
        $index = $result['indexStatusResult'] ?? [];
        $verdict = $index['verdict'] ?? null;

        return [
            'url' => $url,
            'is_indexed' => $verdict === 'PASS' ? true : ($verdict === null ? null : false),
            'verdict' => $verdict,
            'coverage_state' => $index['coverageState'] ?? null,
            'indexing_state' => $index['indexingState'] ?? null,
            'robots_txt_state' => $index['robotsTxtState'] ?? null,
            'page_fetch_state' => $index['pageFetchState'] ?? null,
            'last_crawl_time' => $index['lastCrawlTime'] ?? null,
            'google_canonical' => $index['googleCanonical'] ?? null,
            'user_canonical' => $index['userCanonical'] ?? null,
            'referring_urls' => $index['referringUrls'] ?? [],
            'sitemaps' => $index['sitemap'] ?? [],
            'inspection_result_link' => $result['inspectionResultLink'] ?? null,
            'error' => null,
        ];
    }

    /**
     * @param  array<int, string>  $sitemapUrls
     * @return array<int, string>
     */
    private function collectUrlsFromSitemaps(array $sitemapUrls, GaProperty $property, int $limit): array
    {
        $discovered = [];
        $pending = array_values(array_unique($sitemapUrls));
        $seenSitemaps = [];
        $expectedHost = parse_url($property->website_url ?? '', PHP_URL_HOST);

        while ($pending !== [] && count($discovered) < $limit) {
            $current = array_shift($pending);

            if (! is_string($current) || isset($seenSitemaps[$current])) {
                continue;
            }

            $seenSitemaps[$current] = true;

            [$childSitemaps, $pageUrls] = $this->fetchSitemapDocument($current);

            foreach ($pageUrls as $pageUrl) {
                if (! $this->isInspectableUrl($pageUrl, $expectedHost)) {
                    continue;
                }

                $discovered[$pageUrl] = $pageUrl;

                if (count($discovered) >= $limit) {
                    break 2;
                }
            }

            foreach ($childSitemaps as $childSitemap) {
                if (! isset($seenSitemaps[$childSitemap])) {
                    $pending[] = $childSitemap;
                }
            }
        }

        return array_values($discovered);
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function fetchSitemapDocument(string $sitemapUrl): array
    {
        $response = Http::timeout(20)
            ->connectTimeout(5)
            ->retry(1, 500, throw: false)
            ->get($sitemapUrl);

        if ($response->failed()) {
            return [[], []];
        }

        $xml = @simplexml_load_string($response->body(), SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

        if (! $xml instanceof SimpleXMLElement) {
            return [[], []];
        }

        $childSitemaps = collect($xml->xpath('//*[local-name()="sitemap"]/*[local-name()="loc"]') ?: [])
            ->map(fn ($node): string => trim((string) $node))
            ->filter()
            ->values()
            ->all();

        $pageUrls = collect($xml->xpath('//*[local-name()="url"]/*[local-name()="loc"]') ?: [])
            ->map(fn ($node): string => trim((string) $node))
            ->filter()
            ->values()
            ->all();

        return [$childSitemaps, $pageUrls];
    }

    private function isInspectableUrl(string $url, ?string $expectedHost): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        if (! $expectedHost) {
            return true;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host)) {
            return false;
        }

        $normalizedExpected = str_starts_with($expectedHost, 'www.') ? substr($expectedHost, 4) : $expectedHost;
        $normalizedHost = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        return $normalizedHost === $normalizedExpected;
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

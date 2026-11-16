<?php

namespace App\Services;

use App\Exceptions\SearchConsoleApiException;
use App\Models\GaConnection;
use App\Models\GaProperty;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SimpleXMLElement;

class SearchConsoleService
{
    private const SITES_API = 'https://searchconsole.googleapis.com/webmasters/v3/sites/';

    /** Maximum rows Google returns per Search Analytics request. */
    public const ROW_LIMIT = 25000;

    /** Safety net so a runaway pagination loop cannot spin forever. */
    private const MAX_PAGES = 40;

    public function __construct(
        private GoogleTokenService $tokenService,
    ) {}

    /**
     * Fetch Search Analytics rows for a date range, broken down by date, query
     * and page. Pages through `startRow` until Google stops returning full
     * pages, so a range never silently loses rows past the first 25k.
     *
     * @return array<int, array{date: string, query: string, page: string|null, clicks: int, impressions: int, ctr: float, position: float}>
     *
     * @throws SearchConsoleApiException when the site cannot be resolved or the API call fails
     */
    public function fetchDailyRows(GaProperty $property, string $startDate, string $endDate): array
    {
        $siteUrl = $this->resolveSiteUrl($property);

        if (! $siteUrl) {
            Log::warning("Search Console: no matching site for {$property->display_name} ({$property->website_url})");

            throw new SearchConsoleApiException("No Search Console site matches {$property->display_name} ({$property->website_url}).");
        }

        $token = $this->tokenService->getFreshToken($property->gaConnection);
        $rows = [];
        $startRow = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $batch = $this->requestSearchAnalytics($property, $siteUrl, $token, $startDate, $endDate, $startRow);
            $rows = array_merge($rows, array_map($this->mapRow(...), $batch));

            if (count($batch) < self::ROW_LIMIT) {
                return $rows;
            }

            $startRow += self::ROW_LIMIT;
        }

        Log::warning("Search Console: hit the pagination cap for {$property->display_name} ({$startDate} to {$endDate}), rows may be truncated");

        return $rows;
    }

    /**
     * Perform a single Search Analytics page request.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws SearchConsoleApiException
     */
    private function requestSearchAnalytics(GaProperty $property, string $siteUrl, string $token, string $startDate, string $endDate, int $startRow): array
    {
        $response = Http::withToken($token)
            ->timeout(120)
            ->connectTimeout(5)
            ->retry(3, 2000, throw: false)
            ->post(
                "https://searchconsole.googleapis.com/webmasters/v3/sites/{$siteUrl}/searchAnalytics/query",
                [
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                    'dimensions' => ['date', 'query', 'page'],
                    'rowLimit' => self::ROW_LIMIT,
                    'startRow' => $startRow,
                    'type' => 'web',
                ]
            );

        if ($response->failed()) {
            Log::warning("Search Console API error for {$property->display_name} ({$siteUrl}): HTTP {$response->status()} {$response->body()}");
            $this->forgetCachedSiteUrl($property);

            throw new SearchConsoleApiException("Search Console API error for {$property->display_name}: HTTP {$response->status()}");
        }

        return $response->json('rows', []);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{date: string, query: string, page: string|null, clicks: int, impressions: int, ctr: float, position: float}
     */
    private function mapRow(array $row): array
    {
        return [
            'date' => $row['keys'][0] ?? '',
            'query' => $row['keys'][1] ?? '',
            'page' => $row['keys'][2] ?? null,
            'clicks' => (int) ($row['clicks'] ?? 0),
            'impressions' => (int) ($row['impressions'] ?? 0),
            'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
            'position' => round($row['position'] ?? 0, 1),
        ];
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
     * List sitemaps available for the property; empty when Search Console
     * cannot be reached (used for URL discovery, where a miss is not fatal).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listSitemaps(GaProperty $property): array
    {
        try {
            return $this->sitemaps($property);
        } catch (SearchConsoleApiException $e) {
            Log::warning("Search Console sitemap API error for {$property->display_name}: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * Sitemaps submitted for the property with their processing status.
     * Google returns only error and warning counts, not the error details.
     *
     * @return array<int, array{path: string, type: ?string, is_pending: bool, is_sitemaps_index: bool, last_submitted: ?string, last_downloaded: ?string, warnings: int, errors: int, contents: list<array{type: ?string, submitted: int}>}>
     *
     * @throws SearchConsoleApiException
     */
    public function sitemaps(GaProperty $property): array
    {
        $response = $this->siteRequest($property)
            ->get(self::SITES_API.urlencode($this->siteFor($property)).'/sitemaps');

        $this->throwIfFailed($response, 'lettura delle sitemap');

        return collect($response->json('sitemap', []))
            ->filter(fn (array $sitemap): bool => filled($sitemap['path'] ?? null))
            ->map(fn (array $sitemap): array => [
                'path' => $sitemap['path'],
                'type' => $sitemap['type'] ?? null,
                'is_pending' => (bool) ($sitemap['isPending'] ?? false),
                'is_sitemaps_index' => (bool) ($sitemap['isSitemapsIndex'] ?? false),
                'last_submitted' => $sitemap['lastSubmitted'] ?? null,
                'last_downloaded' => $sitemap['lastDownloaded'] ?? null,
                'warnings' => (int) ($sitemap['warnings'] ?? 0),
                'errors' => (int) ($sitemap['errors'] ?? 0),
                'contents' => array_map(fn (array $content): array => [
                    'type' => $content['type'] ?? null,
                    'submitted' => (int) ($content['submitted'] ?? 0),
                ], $sitemap['contents'] ?? []),
            ])
            ->values()
            ->all();
    }

    /**
     * Submit (or resubmit) a sitemap: Google queues it for download.
     *
     * @throws SearchConsoleApiException
     */
    public function submitSitemap(GaProperty $property, string $sitemapUrl): void
    {
        $response = $this->siteRequest($property)
            ->put(self::SITES_API.urlencode($this->siteFor($property)).'/sitemaps/'.urlencode($sitemapUrl));

        $this->throwIfFailed($response, 'invio della sitemap');
    }

    /**
     * Remove a sitemap from Search Console. The pages already known to Google
     * stay indexed; Google only stops reading that file.
     *
     * @throws SearchConsoleApiException
     */
    public function deleteSitemap(GaProperty $property, string $sitemapUrl): void
    {
        $response = $this->siteRequest($property)
            ->delete(self::SITES_API.urlencode($this->siteFor($property)).'/sitemaps/'.urlencode($sitemapUrl));

        $this->throwIfFailed($response, 'rimozione della sitemap');
    }

    /**
     * Live Search Analytics query with any dimensions, filters and search type,
     * for what the stored per-day query/page rows do not cover (country,
     * device, search appearance, image/video/news/Discover, fresh or hourly
     * data).
     *
     * @param  array<string, mixed>  $body  Search Analytics request body
     * @return list<array{keys: list<string>, clicks: int, impressions: int, ctr: float, position: float}>
     *
     * @throws SearchConsoleApiException
     */
    public function querySearchAnalytics(GaProperty $property, array $body): array
    {
        $response = $this->siteRequest($property)
            ->timeout(60)
            ->post(self::SITES_API.urlencode($this->siteFor($property)).'/searchAnalytics/query', $body);

        $this->throwIfFailed($response, 'query Search Analytics');

        return array_map(fn (array $row): array => [
            'keys' => array_map('strval', $row['keys'] ?? []),
            'clicks' => (int) ($row['clicks'] ?? 0),
            'impressions' => (int) ($row['impressions'] ?? 0),
            'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
            'position' => round($row['position'] ?? 0, 1),
        ], $response->json('rows', []));
    }

    /**
     * The exact Search Console site of a property (sc-domain:host or a URL
     * prefix).
     *
     * @throws SearchConsoleApiException
     */
    public function siteFor(GaProperty $property): string
    {
        $siteUrl = $this->resolveSiteUrl($property);

        if (! $siteUrl) {
            throw new SearchConsoleApiException("Nessun sito Search Console corrisponde a {$property->display_name} ({$property->website_url}) con l'account Google collegato.");
        }

        return urldecode($siteUrl);
    }

    private function siteRequest(GaProperty $property): PendingRequest
    {
        return Http::withToken($this->tokenService->getFreshToken($property->gaConnection))
            ->acceptJson()
            ->timeout(30)
            ->connectTimeout(5);
    }

    /**
     * @throws SearchConsoleApiException
     */
    private function throwIfFailed(Response $response, string $action): void
    {
        if ($response->successful()) {
            return;
        }

        $message = (string) ($response->json('error.message') ?? Str::limit($response->body(), 200));
        $reason = (string) ($response->json('error.details.0.reason') ?? $response->json('error.status') ?? '');

        if ($response->status() === 403 && (str_contains($reason, 'SCOPE') || Str::contains($message, 'insufficient', ignoreCase: true))) {
            throw new SearchConsoleApiException("L'account Google ha solo il permesso di lettura su Search Console: ricollegalo in Impostazioni → Google di Pulso per concedere anche la scrittura ({$action}).");
        }

        if ($response->status() === 403) {
            throw new SearchConsoleApiException("Search Console ha rifiutato la {$action}: l'account collegato deve essere proprietario o utente con accesso completo del sito. Dettaglio di Google: {$message}");
        }

        throw new SearchConsoleApiException("Errore Search Console nella {$action} (HTTP {$response->status()}): {$message}");
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
        return $this->listSitesForConnection($property->gaConnection);
    }

    /**
     * Search Console sites the Google account can read; unverified entries are
     * left out because their data is not accessible.
     *
     * @return array<int, string>
     */
    public function listSitesForConnection(GaConnection $connection): array
    {
        $token = $this->tokenService->getFreshToken($connection);

        $response = Http::withToken($token)
            ->timeout(15)
            ->get('https://searchconsole.googleapis.com/webmasters/v3/sites');

        if ($response->failed()) {
            return [];
        }

        return collect($response->json('siteEntry', []))
            ->reject(fn (array $site): bool => ($site['permissionLevel'] ?? null) === 'siteUnverifiedUser')
            ->pluck('siteUrl')
            ->values()
            ->all();
    }

    /**
     * The website URL of a Search Console site: https://host for a Domain
     * property, the prefix without the trailing slash for a URL-prefix one.
     */
    public static function websiteUrlFor(string $siteUrl): string
    {
        if (str_starts_with($siteUrl, 'sc-domain:')) {
            return 'https://'.substr($siteUrl, strlen('sc-domain:'));
        }

        return rtrim($siteUrl, '/');
    }

    /**
     * Resolve the Search Console site identifier from the property's website_url.
     * Prefers a Domain property ("sc-domain:host"), falls back to a URL-prefix
     * site that matches the same host (with or without "www."). Result is cached
     * per property because the Search Console site list rarely changes.
     */
    private function resolveSiteUrl(GaProperty $property): ?string
    {
        if ($property->gsc_site_url) {
            return urlencode($property->gsc_site_url);
        }

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
                'crawled_as' => null,
                'rich_results' => null,
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
                'error' => "HTTP {$response->status()}: ".($response->json('error.message') ?? 'errore sconosciuto'),
            ];
        }

        $result = $response->json('inspectionResult', []);
        $index = $result['indexStatusResult'] ?? [];
        $verdict = $index['verdict'] ?? null;

        $richResults = $result['richResultsResult'] ?? null;

        return [
            'url' => $url,
            'crawled_as' => $index['crawledAs'] ?? null,
            'rich_results' => $richResults === null ? null : [
                'verdict' => $richResults['verdict'] ?? null,
                'types' => collect($richResults['detectedItems'] ?? [])->map(fn (array $item): array => [
                    'type' => $item['richResultType'] ?? null,
                    'items' => count($item['items'] ?? []),
                    'issues' => collect($item['items'] ?? [])
                        ->flatMap(fn (array $entry): array => $entry['issues'] ?? [])
                        ->map(fn (array $issue): string => ($issue['severity'] ?? 'ISSUE').': '.($issue['issueMessage'] ?? ''))
                        ->unique()
                        ->values()
                        ->all(),
                ])->values()->all(),
            ],
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
}

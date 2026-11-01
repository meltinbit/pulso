<?php

namespace App\Services;

use App\Exceptions\GoogleAdsApiException;
use App\Models\KeywordResearchCache;
use Illuminate\Support\Str;

/**
 * Keyword research on top of the Google Ads KeywordPlanIdeaService and
 * GeoTargetConstantService. Responses are cached in the database for
 * `services.google_ads.cache_days` days: search volumes are monthly, so
 * asking again within the month only burns quota.
 */
class GoogleAdsKeywordService
{
    /** Google caps keyword seeds at 20 per request. */
    public const MAX_SEEDS = 20;

    /** Upper bound for keyword ideas returned per request. */
    public const MAX_IDEAS = 1000;

    /** Upper bound for keywords per historical metrics request. */
    public const MAX_METRIC_KEYWORDS = 1000;

    /** Google caps geo targets at 10 per request. */
    public const MAX_GEO_TARGETS = 10;

    private const MONTHS = [
        'JANUARY' => 1, 'FEBRUARY' => 2, 'MARCH' => 3, 'APRIL' => 4, 'MAY' => 5, 'JUNE' => 6,
        'JULY' => 7, 'AUGUST' => 8, 'SEPTEMBER' => 9, 'OCTOBER' => 10, 'NOVEMBER' => 11, 'DECEMBER' => 12,
    ];

    public function __construct(
        private GoogleAdsClient $client,
    ) {}

    /**
     * Generate keyword ideas from seed keywords and/or a URL on the Google Search network.
     *
     * @param  array<int, string>  $seeds
     * @param  array<int, string|int>  $geoTargetIds
     * @return array<int, array{keyword: string, volume: int|null, monthly: array<int, array{month: string, searches: int}>, trend: float|null, competition: string|null, competition_index: int|null, cpc_low: float|null, cpc_high: float|null}>
     *
     * @throws GoogleAdsApiException
     */
    public function keywordIdeas(array $seeds, ?string $url = null, array $geoTargetIds = [], ?string $language = null, int $limit = 100): array
    {
        $seeds = $this->cleanKeywords($seeds, self::MAX_SEEDS);
        $url = filled($url) ? trim($url) : null;

        if ($seeds === [] && $url === null) {
            throw new GoogleAdsApiException('Indica almeno una keyword di partenza o un URL.', 'INVALID_INPUT');
        }

        $params = [
            'seeds' => $seeds,
            'url' => $url,
            'geo' => $this->geoTargetIds($geoTargetIds),
            'language' => $this->languageId($language),
            'limit' => max(1, min($limit, self::MAX_IDEAS)),
        ];

        $payload = $this->remember('ideas', $params, function () use ($params): array {
            $body = $this->targetingBody($params['geo'], $params['language']) + [
                'includeAdultKeywords' => false,
                'pageSize' => $params['limit'],
            ];

            $body += match (true) {
                $params['seeds'] !== [] && $params['url'] !== null => ['keywordAndUrlSeed' => ['url' => $params['url'], 'keywords' => $params['seeds']]],
                $params['url'] !== null => ['urlSeed' => ['url' => $params['url']]],
                default => ['keywordSeed' => ['keywords' => $params['seeds']]],
            };

            $response = $this->client->post("customers/{$this->client->customerId()}:generateKeywordIdeas", $body);

            return array_map(
                fn (array $result): array => $this->mapMetrics($result['text'] ?? '', $result['keywordIdeaMetrics'] ?? []),
                $response['results'] ?? [],
            );
        });

        return array_slice($payload, 0, $params['limit']);
    }

    /**
     * Historical metrics for specific keywords. Google deduplicates close
     * variants, so "scarpa" and "scarpe" may come back as a single row.
     *
     * @param  array<int, string>  $keywords
     * @param  array<int, string|int>  $geoTargetIds
     * @return array<int, array{keyword: string, volume: int|null, monthly: array<int, array{month: string, searches: int}>, trend: float|null, competition: string|null, competition_index: int|null, cpc_low: float|null, cpc_high: float|null, close_variants: array<int, string>}>
     *
     * @throws GoogleAdsApiException
     */
    public function keywordMetrics(array $keywords, array $geoTargetIds = [], ?string $language = null): array
    {
        $keywords = $this->cleanKeywords($keywords, self::MAX_METRIC_KEYWORDS);

        if ($keywords === []) {
            throw new GoogleAdsApiException('Indica almeno una keyword.', 'INVALID_INPUT');
        }

        $params = [
            'keywords' => $keywords,
            'geo' => $this->geoTargetIds($geoTargetIds),
            'language' => $this->languageId($language),
        ];

        return $this->remember('metrics', $params, function () use ($params): array {
            $body = $this->targetingBody($params['geo'], $params['language']) + [
                'keywords' => $params['keywords'],
                'includeAdultKeywords' => false,
            ];

            $response = $this->client->post("customers/{$this->client->customerId()}:generateKeywordHistoricalMetrics", $body);

            return array_map(
                fn (array $result): array => $this->mapMetrics($result['text'] ?? '', $result['keywordMetrics'] ?? []) + [
                    'close_variants' => array_values($result['closeVariants'] ?? []),
                ],
                $response['results'] ?? [],
            );
        });
    }

    /**
     * Find geo target constants (countries, regions, provinces, cities) by name.
     *
     * @return array<int, array{id: string, name: string, type: string|null, canonical_name: string|null, country_code: string|null, reach: int|null}>
     *
     * @throws GoogleAdsApiException
     */
    public function searchGeoTargets(string $query, ?string $countryCode = 'IT', string $locale = 'it'): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $params = ['query' => mb_strtolower($query), 'country' => $countryCode, 'locale' => $locale];

        return $this->remember('geo', $params, function () use ($query, $countryCode, $locale): array {
            $body = ['locale' => $locale, 'locationNames' => ['names' => [$query]]];

            if ($countryCode) {
                $body['countryCode'] = $countryCode;
            }

            $response = $this->client->post('geoTargetConstants:suggest', $body);

            return collect($response['geoTargetConstantSuggestions'] ?? [])
                ->filter(fn (array $suggestion): bool => ($suggestion['geoTargetConstant']['status'] ?? 'ENABLED') === 'ENABLED')
                ->map(fn (array $suggestion): array => [
                    'id' => (string) ($suggestion['geoTargetConstant']['id'] ?? Str::afterLast($suggestion['geoTargetConstant']['resourceName'] ?? '', '/')),
                    'name' => (string) ($suggestion['geoTargetConstant']['name'] ?? ''),
                    'type' => $suggestion['geoTargetConstant']['targetType'] ?? null,
                    'canonical_name' => $suggestion['geoTargetConstant']['canonicalName'] ?? null,
                    'country_code' => $suggestion['geoTargetConstant']['countryCode'] ?? null,
                    'reach' => isset($suggestion['reach']) ? (int) $suggestion['reach'] : null,
                ])
                ->values()
                ->all();
        });
    }

    /**
     * Turn location inputs (numeric geo target IDs or place names) into geo target IDs.
     * Names are resolved with the best geo target suggestion.
     *
     * @param  array<int, string|int>  $locations
     * @return array<int, string>
     *
     * @throws GoogleAdsApiException
     */
    public function resolveLocations(array $locations): array
    {
        $ids = [];

        foreach (array_filter(array_map(fn (string|int $location): string => trim((string) $location), $locations)) as $location) {
            if (preg_match('/^(geoTargetConstants\/)?\d+$/', $location)) {
                $ids[] = Str::afterLast($location, '/');

                continue;
            }

            $match = $this->searchGeoTargets($location, null)[0] ?? null;

            if (! $match) {
                throw new GoogleAdsApiException("Località \"{$location}\" non trovata. Usa search-geo-targets per trovare l'ID.", 'GEO_NOT_FOUND');
            }

            $ids[] = $match['id'];
        }

        return $this->geoTargetIds($ids);
    }

    /**
     * Resolve a language code (it, en…) or language constant ID, falling back to the default.
     */
    public function languageId(?string $language): string
    {
        $language = trim((string) $language);

        if ($language === '') {
            return (string) config('services.google_ads.default_language', '1004');
        }

        if (preg_match('/^(languageConstants\/)?\d+$/', $language)) {
            return Str::afterLast($language, '/');
        }

        $languages = config('services.google_ads.languages', []);
        $code = Str::lower(Str::before($language, '-'));

        if (! isset($languages[$code])) {
            throw new GoogleAdsApiException("Lingua \"{$language}\" non supportata. Usa uno di: ".implode(', ', array_keys($languages)).' oppure un ID languageConstant.', 'INVALID_LANGUAGE');
        }

        return (string) $languages[$code];
    }

    /**
     * @param  array<int, string|int>  $ids
     * @return array<int, string>
     */
    private function geoTargetIds(array $ids): array
    {
        $ids = collect($ids)
            ->map(fn (string|int $id): string => Str::afterLast((string) $id, '/'))
            ->filter(fn (string $id): bool => ctype_digit($id))
            ->unique()
            ->sort()
            ->values()
            ->take(self::MAX_GEO_TARGETS)
            ->all();

        return $ids === [] ? [(string) config('services.google_ads.default_geo', '2380')] : $ids;
    }

    /**
     * @param  array<int, string>  $geo
     * @return array<string, mixed>
     */
    private function targetingBody(array $geo, string $language): array
    {
        return [
            'language' => "languageConstants/{$language}",
            'geoTargetConstants' => array_map(fn (string $id): string => "geoTargetConstants/{$id}", $geo),
            'keywordPlanNetwork' => 'GOOGLE_SEARCH',
        ];
    }

    /**
     * @param  array<int, mixed>  $keywords
     * @return array<int, string>
     */
    private function cleanKeywords(array $keywords, int $max): array
    {
        return collect($keywords)
            ->map(fn (mixed $keyword): string => Str::squish(mb_strtolower((string) $keyword)))
            ->filter()
            ->unique()
            ->values()
            ->take($max)
            ->all();
    }

    /**
     * Normalize a KeywordPlanHistoricalMetrics payload. Google sends int64
     * values as strings and bids in micros of the account currency.
     *
     * @param  array<string, mixed>  $metrics
     * @return array{keyword: string, volume: int|null, monthly: array<int, array{month: string, searches: int}>, trend: float|null, competition: string|null, competition_index: int|null, cpc_low: float|null, cpc_high: float|null}
     */
    private function mapMetrics(string $keyword, array $metrics): array
    {
        $monthly = collect($metrics['monthlySearchVolumes'] ?? [])
            ->filter(fn (array $month): bool => isset($month['year'], $month['month'], self::MONTHS[$month['month']]))
            ->map(fn (array $month): array => [
                'month' => sprintf('%04d-%02d', (int) $month['year'], self::MONTHS[$month['month']]),
                'searches' => (int) ($month['monthlySearches'] ?? 0),
            ])
            ->sortBy('month')
            ->values()
            ->all();

        $competition = $metrics['competition'] ?? null;

        return [
            'keyword' => $keyword,
            'volume' => isset($metrics['avgMonthlySearches']) ? (int) $metrics['avgMonthlySearches'] : null,
            'monthly' => $monthly,
            'trend' => $this->trend($monthly),
            'competition' => in_array($competition, ['LOW', 'MEDIUM', 'HIGH'], true) ? $competition : null,
            'competition_index' => isset($metrics['competitionIndex']) ? (int) $metrics['competitionIndex'] : null,
            'cpc_low' => $this->fromMicros($metrics['lowTopOfPageBidMicros'] ?? null),
            'cpc_high' => $this->fromMicros($metrics['highTopOfPageBidMicros'] ?? null),
        ];
    }

    /**
     * Percent change of the last three months against the first three of the series.
     *
     * @param  array<int, array{month: string, searches: int}>  $monthly
     */
    private function trend(array $monthly): ?float
    {
        if (count($monthly) < 6) {
            return null;
        }

        $first = array_sum(array_column(array_slice($monthly, 0, 3), 'searches'));
        $last = array_sum(array_column(array_slice($monthly, -3), 'searches'));

        if ($first === 0) {
            return null;
        }

        return round(($last - $first) * 100 / $first, 1);
    }

    private function fromMicros(mixed $micros): ?float
    {
        return $micros === null ? null : round(((int) $micros) / 1_000_000, 2);
    }

    /**
     * Return the cached payload for these inputs, or run the request and cache it.
     *
     * @param  array<string, mixed>  $params
     * @return array<int, array<string, mixed>>
     */
    private function remember(string $operation, array $params, callable $fn): array
    {
        $key = hash('sha256', $operation.'|'.config('services.google_ads.customer_id').'|'.json_encode($params));

        $cached = KeywordResearchCache::where('cache_key', $key)
            ->where('expires_at', '>', now())
            ->first();

        if ($cached) {
            return $cached->payload;
        }

        $payload = $fn();

        KeywordResearchCache::updateOrCreate(
            ['cache_key' => $key],
            [
                'operation' => $operation,
                'params' => $params,
                'payload' => $payload,
                'expires_at' => now()->addDays((int) config('services.google_ads.cache_days', 30)),
            ],
        );

        return $payload;
    }
}

<?php

namespace App\Services;

use App\Models\GaProperty;
use App\Models\PropertyBingCrawlStat;
use App\Models\PropertyBingSearchStat;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Links Pulso properties to Bing Webmaster Tools sites and stores Bing data.
 * The API key lives in app_settings (group bing), encrypted, like the other
 * credentials.
 */
class BingSyncService
{
    public const SETTING_KEY = 'bing_api_key';

    public const ALERTED_SETTING_KEY = 'bing_api_key_alerted';

    private const CHUNK = 500;

    public function __construct(
        private BingWebmasterService $bing,
        private SettingService $settings,
    ) {}

    public function apiKey(User $user): ?string
    {
        return $this->settings->get($user->id, self::SETTING_KEY) ?: null;
    }

    /**
     * Fetch the user's Bing sites and link every active property without a
     * Bing site to the verified site with the same host (www and trailing
     * slash ignored). Returns the sites with the property linked to each.
     *
     * @return list<array{url: string, is_verified: bool, property_id: ?int}>
     */
    public function matchSites(User $user, string $apiKey): array
    {
        $sites = $this->bing->userSites($apiKey);
        $properties = $user->gaProperties()->where('is_active', true)->get();

        foreach ($properties->whereNull('bing_site_url') as $property) {
            $host = self::host($property->website_url);

            $match = collect($sites)->first(fn (array $site): bool => $site['is_verified'] && $host !== null && self::host($site['url']) === $host);

            if ($match) {
                $property->update(['bing_site_url' => $match['url']]);
            }
        }

        $linked = $properties->filter(fn (GaProperty $property): bool => $property->bing_site_url !== null)
            ->mapWithKeys(fn (GaProperty $property): array => [self::normalize($property->bing_site_url) => $property->id]);

        return array_map(fn (array $site): array => $site + [
            'property_id' => $linked[self::normalize($site['url'])] ?? null,
        ], $sites);
    }

    /**
     * Download every Bing dataset for a property and replace what is stored:
     * Bing returns the whole history on each call and keeps revising the
     * latest weeks.
     *
     * @return array{traffic: int, queries: int, pages: int, crawl: int}
     */
    public function sync(GaProperty $property, string $apiKey): array
    {
        $site = (string) $property->bing_site_url;

        $traffic = $this->bing->trafficStats($apiKey, $site);
        $queries = $this->bing->queryStats($apiKey, $site);
        $pages = $this->bing->pageStats($apiKey, $site);
        $crawl = $this->bing->crawlStats($apiKey, $site, PropertyBingCrawlStat::COUNTERS);

        DB::transaction(function () use ($property, $traffic, $queries, $pages, $crawl): void {
            $this->upsertDaily($property, 'property_bing_traffic', $traffic, ['clicks', 'impressions']);
            $this->upsertDaily($property, 'property_bing_crawl_stats', $crawl, array_keys(PropertyBingCrawlStat::COUNTERS));
            $this->replaceWeekly($property, PropertyBingSearchStat::DIMENSION_QUERY, $queries);
            $this->replaceWeekly($property, PropertyBingSearchStat::DIMENSION_PAGE, $pages);
        });

        return [
            'traffic' => count($traffic),
            'queries' => count($queries),
            'pages' => count($pages),
            'crawl' => count($crawl),
        ];
    }

    /**
     * Host of a URL without www, lower case.
     */
    public static function host(?string $url): ?string
    {
        $host = $url ? parse_url($url, PHP_URL_HOST) : null;

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private static function normalize(string $url): string
    {
        return rtrim(strtolower($url), '/');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $columns
     */
    private function upsertDaily(GaProperty $property, string $table, array $rows, array $columns): void
    {
        $rows = array_map(fn (array $row): array => ['ga_property_id' => $property->id] + $row, $rows);

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table($table)->upsert($chunk, ['ga_property_id', 'date'], $columns);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceWeekly(GaProperty $property, string $dimension, array $rows): void
    {
        $property->bingSearchStats()->where('dimension', $dimension)->delete();

        $rows = array_map(fn (array $row): array => [
            'ga_property_id' => $property->id,
            'dimension' => $dimension,
            'value' => mb_substr($row['value'], 0, 1024),
        ] + $row, $rows);

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('property_bing_search_stats')->insert($chunk);
        }
    }
}

<?php

namespace App\Mcp\Tools;

use App\Exceptions\GoogleAdsApiException;
use App\Mcp\Tools\Concerns\FormatsAdsReport;
use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Models\GaProperty;
use App\Models\PropertySearchQuery;
use App\Services\GoogleAdsReportService;
use App\Services\KeywordGapService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Paid vs organic: the Google Ads search terms that convert, limited to the ad groups whose ads land on the property\'s domain (or its subdomains), matched with the property\'s Search Console data for the same period (exact query, case and accent insensitive). Each term is marked missing (no organic impressions), weak (average position worse than position_threshold) or strong, with organic clicks, impressions, position and best landing page. By default only missing and weak terms are returned: they are the SEO pages worth creating or improving, because they are proven to convert. Defaults to the last 90 complete days; output is columnar, sorted by conversions.')]
#[IsReadOnly]
#[IsIdempotent]
class GetPaidOrganicGapTool extends Tool
{
    use FormatsAdsReport, ResolvesMcpContext;

    public function __construct(private GoogleAdsReportService $reports) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => 'required|integer',
            'customer_id' => 'nullable|string|max:20',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'min_conversions' => 'nullable|numeric|min:0',
            'position_threshold' => 'nullable|numeric|min:1|max:100',
            'include_strong' => 'nullable|boolean',
            'campaign_id' => 'nullable|string|max:20',
            'all_sites' => 'nullable|boolean',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        $property = $this->resolveAuthorizedProperty($validated['property_id']);
        [$from, $to] = $this->adsRange($validated, 90);
        $minConversions = (float) ($validated['min_conversions'] ?? 0);
        $threshold = (float) ($validated['position_threshold'] ?? 10);

        $allSites = (bool) ($validated['all_sites'] ?? false);
        $host = GoogleAdsReportService::host($property->website_url);

        if (! $allSites && $host === null) {
            return Response::error("La property {$property->display_name} non ha un website_url: non so quali annunci portano al sito. Impostalo o passa all_sites=true.");
        }

        try {
            $customerId = $this->reports->resolveCustomerId($validated['customer_id'] ?? null);
            $adGroupIds = $allSites ? null : $this->reports->adGroupsForHost($customerId, $host);
            $terms = collect($this->reports->searchTerms($customerId, $from, $to, $validated['campaign_id'] ?? null, $adGroupIds))
                ->filter(fn (array $term): bool => $minConversions > 0 ? $term['conversions'] >= $minConversions : $term['conversions'] > 0);
        } catch (GoogleAdsApiException $e) {
            return Response::error($e->getMessage());
        }

        $organic = $this->organic($property, $from, $to, $terms->pluck('term')->all());

        $rows = $terms
            ->map(function (array $term) use ($organic, $threshold): array {
                $match = $organic[KeywordGapService::normalize($term['term'])] ?? null;

                return $term + [
                    'cpa' => $this->derived($term)['cpa'],
                    'organic' => $match,
                    'gap' => match (true) {
                        $match === null => 'missing',
                        $match['position'] > $threshold => 'weak',
                        default => 'strong',
                    },
                ];
            })
            ->when(! ($validated['include_strong'] ?? false), fn ($rows) => $rows->where('gap', '!=', 'strong'))
            ->sortByDesc('conversions')
            ->values();

        return Response::text(json_encode([
            'property' => $property->display_name,
            'customer_id' => $customerId,
            'site' => $allSites ? 'all' : $host,
            'ad_groups_on_site' => $adGroupIds === null ? null : count($adGroupIds),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'summary' => [
                'converting_terms' => $terms->count(),
                'missing' => $rows->where('gap', 'missing')->count(),
                'weak' => $rows->where('gap', 'weak')->count(),
                'conversions_without_strong_page' => round($rows->where('gap', '!=', 'strong')->sum('conversions'), 2),
            ],
            'cols' => ['term', 'gap', 'conversions', 'cost', 'cpa', 'organic_impressions', 'organic_clicks', 'organic_position', 'organic_page'],
            'rows' => $rows->take($validated['limit'] ?? 100)->map(fn (array $row): array => [
                $row['term'], $row['gap'], $row['conversions'], $row['cost'], $row['cpa'],
                $row['organic']['impressions'] ?? 0,
                $row['organic']['clicks'] ?? 0,
                $row['organic']['position'] ?? null,
                $row['organic']['page'] ?? null,
            ])->values(),
        ]));
    }

    /**
     * Search Console totals of the given queries over the range, keyed by
     * normalized query, with the page that earned the most clicks.
     *
     * @param  list<string>  $terms
     * @return array<string, array{impressions: int, clicks: int, position: float, page: ?string}>
     */
    private function organic(GaProperty $property, Carbon $from, Carbon $to, array $terms): array
    {
        if ($terms === []) {
            return [];
        }

        $candidates = collect($terms)
            ->flatMap(fn (string $term): array => [mb_strtolower($term), Str::ascii(mb_strtolower($term))])
            ->unique()
            ->values();

        $base = fn () => PropertySearchQuery::query()
            ->where('ga_property_id', $property->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()]);

        $result = [];

        foreach ($candidates->chunk(500) as $chunk) {
            $totals = $base()->whereIn('query', $chunk->all())
                ->selectRaw('`query`, SUM(clicks) as clicks, SUM(impressions) as impressions, SUM(position * impressions) * 1.0 / NULLIF(SUM(impressions), 0) as position')
                ->groupBy('query')
                ->get();

            $pages = $base()->whereIn('query', $chunk->all())
                ->selectRaw('`query`, page, SUM(clicks) as clicks, SUM(impressions) as impressions')
                ->groupBy('query', 'page')
                ->get()
                ->sortByDesc(fn ($row): array => [(int) $row->clicks, (int) $row->impressions])
                ->unique('query')
                ->pluck('page', 'query');

            foreach ($totals as $row) {
                $key = KeywordGapService::normalize($row->query);
                $existing = $result[$key] ?? null;

                if ($existing === null || (int) $row->impressions > $existing['impressions']) {
                    $result[$key] = [
                        'impressions' => (int) $row->impressions,
                        'clicks' => (int) $row->clicks,
                        'position' => round((float) $row->position, 1),
                        'page' => $pages[$row->query] ?? null,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'property_id' => $schema->integer()
                ->description('The internal ID of the property whose Search Console data is compared. Use list-properties to find it.')
                ->required(),
            'customer_id' => $schema->string()
                ->description('Google Ads account ID. Defaults to the configured account; see list-ads-accounts.'),
            'from' => $schema->string()
                ->description('Start date (YYYY-MM-DD). Defaults to 90 days before "to".'),
            'to' => $schema->string()
                ->description('End date (YYYY-MM-DD). Defaults to yesterday.'),
            'min_conversions' => $schema->number()
                ->description('Only terms with more than 0 conversions by default; pass e.g. 2 for terms with at least 2.'),
            'position_threshold' => $schema->number()
                ->description('Average organic position above which a page counts as weak. Defaults to 10 (first page).'),
            'include_strong' => $schema->boolean()
                ->description('Also return terms where the site already ranks well. Defaults to false.'),
            'campaign_id' => $schema->string()
                ->description('Only the search terms of this campaign.'),
            'all_sites' => $schema->boolean()
                ->description('Use every search term of the account, whatever site the ads land on. Defaults to false: only ad groups with ads on the property domain.'),
            'limit' => $schema->integer()
                ->description('Rows to return. Defaults to 100, maximum 500.'),
        ];
    }
}

<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Models\GaProperty;
use App\Models\PropertySearchQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Query to page map from Google Search Console, built from the stored query × page × day rows. view=cannibalization (default) lists the queries where two or more pages of the site each take a real share of the impressions, i.e. compete with each other: per query the competing pages with clicks, impressions, share (%) and average position, the main page (most clicks) and the impressions spread over the other pages. view=pages lists, for each page, its clicks, impressions, number of queries and top queries, marking the queries for which another page is the main one. URL fragments (#...) are merged into their page. Defaults to the last 90 days; output is columnar.')]
#[IsReadOnly]
#[IsIdempotent]
class GetQueryPageMapTool extends Tool
{
    use ResolvesMcpContext;

    /** Queries considered for cannibalization, by impressions. */
    private const MAX_CANDIDATES = 1000;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => 'required|integer',
            'view' => 'nullable|in:cannibalization,pages',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'page' => 'nullable|string|max:2048',
            'query_contains' => 'nullable|string|max:255',
            'min_impressions' => 'nullable|integer|min:1',
            'min_share' => 'nullable|integer|min:1|max:50',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $property = $this->resolveAuthorizedProperty($validated['property_id']);
        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : Carbon::today();
        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : $to->copy()->subDays(89);
        $view = $validated['view'] ?? 'cannibalization';

        $filters = [
            'page' => filled($validated['page'] ?? null) ? $this->path(trim($validated['page'])) : null,
            'query_contains' => filled($validated['query_contains'] ?? null) ? trim($validated['query_contains']) : null,
        ];

        $result = [
            'property' => $property->display_name,
            'view' => $view,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'filters' => array_filter($filters),
        ];

        $result += $view === 'pages'
            ? $this->pages($property, $from, $to, $filters, $validated['limit'] ?? 30)
            : $this->cannibalization($property, $from, $to, $filters, $validated['min_impressions'] ?? 50, $validated['min_share'] ?? 10, $validated['limit'] ?? 30);

        return Response::text(json_encode($result));
    }

    /**
     * Queries whose impressions are split over two or more pages that each
     * hold at least min_share percent of them.
     *
     * @param  array{page: ?string, query_contains: ?string}  $filters
     * @return array<string, mixed>
     */
    private function cannibalization(GaProperty $property, Carbon $from, Carbon $to, array $filters, int $minImpressions, int $minShare, int $limit): array
    {
        $candidates = $this->rows($property, $from, $to, $filters)
            ->groupBy('query')
            ->havingRaw('COUNT(DISTINCT page) > 1')
            ->havingRaw('SUM(impressions) >= ?', [$minImpressions])
            ->orderByRaw('SUM(impressions) DESC')
            ->limit(self::MAX_CANDIDATES)
            ->pluck('query');

        $competing = $this->queryPages($property, $from, $to, $candidates)
            ->map(function (Collection $pages, string $query) use ($minShare): ?array {
                $impressions = $pages->sum('impressions');
                $pages = $pages->map(fn (array $page): array => $page + ['share' => $impressions > 0 ? round($page['impressions'] * 100 / $impressions, 1) : 0.0]);
                $competitors = $pages->where('share', '>=', $minShare)->values();

                if ($competitors->count() < 2) {
                    return null;
                }

                $main = $pages->first();

                return [
                    'query' => $query,
                    'clicks' => $pages->sum('clicks'),
                    'impressions' => $impressions,
                    'main_page' => $main['page'],
                    'impressions_elsewhere' => $impressions - $main['impressions'],
                    'pages' => $competitors->map(fn (array $page): array => [$page['page'], $page['clicks'], $page['impressions'], $page['share'], $page['position']])->all(),
                ];
            })
            ->filter()
            ->sortByDesc('impressions_elsewhere')
            ->values();

        return [
            'summary' => [
                'queries_checked' => $candidates->count(),
                'cannibalized' => $competing->count(),
                'impressions_elsewhere' => $competing->sum('impressions_elsewhere'),
                'min_impressions' => $minImpressions,
                'min_share' => $minShare,
            ],
            'page_cols' => ['page', 'clicks', 'impressions', 'share', 'position'],
            'queries' => $competing->take($limit)->all(),
        ];
    }

    /**
     * Pages by clicks with their top queries; a query whose main page is
     * another one is flagged with that page.
     *
     * @param  array{page: ?string, query_contains: ?string}  $filters
     * @return array<string, mixed>
     */
    private function pages(GaProperty $property, Carbon $from, Carbon $to, array $filters, int $limit): array
    {
        $pageUrls = $this->rows($property, $from, $to, $filters)
            ->reorder()
            ->select('page')
            ->groupBy('page')
            ->orderByRaw('SUM(clicks) DESC, SUM(impressions) DESC')
            ->limit($limit * 3)
            ->pluck('page');

        $rows = $this->rows($property, $from, $to, $filters)
            ->whereIn('page', $pageUrls->all())
            ->groupBy('query', 'page')
            ->selectRaw('page, SUM(clicks) as clicks, SUM(impressions) as impressions, SUM(position * impressions) / NULLIF(SUM(impressions), 0) as position')
            ->get()
            ->map(fn ($row): array => ['query' => $row->query, 'page' => $this->path($row->page), 'clicks' => (int) $row->clicks, 'impressions' => (int) $row->impressions, 'position' => (float) $row->position]);

        $rows = $this->mergeFragments($rows);
        $topQueries = $rows->groupBy('page')
            ->map(fn (Collection $queries, string $page): array => [
                'page' => $page,
                'clicks' => $queries->sum('clicks'),
                'impressions' => $queries->sum('impressions'),
                'queries' => $queries->count(),
                'top' => $queries->sortBy([['clicks', 'desc'], ['impressions', 'desc']])->take(5)->values(),
            ])
            ->sortByDesc('clicks')
            ->take($limit)
            ->values();

        $mainPages = $this->queryPages($property, $from, $to, $topQueries->flatMap(fn (array $page) => $page['top']->pluck('query'))->unique()->values())
            ->map(fn (Collection $pages): string => $pages->first()['page']);

        return [
            'query_cols' => ['query', 'clicks', 'impressions', 'position', 'main_page_if_other'],
            'pages' => $topQueries->map(fn (array $page): array => [
                'page' => $page['page'],
                'clicks' => $page['clicks'],
                'impressions' => $page['impressions'],
                'queries' => $page['queries'],
                'top_queries' => $page['top']->map(fn (array $query): array => [
                    $query['query'], $query['clicks'], $query['impressions'], $query['position'],
                    ($mainPages[$query['query']] ?? $page['page']) === $page['page'] ? null : $mainPages[$query['query']],
                ])->all(),
            ])->all(),
        ];
    }

    /**
     * Every page of the given queries (no page filter), fragments merged,
     * sorted by clicks then impressions so the first one is the main page.
     *
     * @param  Collection<int, string>  $queries
     * @return Collection<string, Collection<int, array{query: string, page: string, clicks: int, impressions: int, position: float}>>
     */
    private function queryPages(GaProperty $property, Carbon $from, Carbon $to, Collection $queries): Collection
    {
        if ($queries->isEmpty()) {
            return collect();
        }

        $rows = $queries->chunk(500)->flatMap(fn (Collection $chunk) => PropertySearchQuery::query()
            ->where('ga_property_id', $property->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('query', $chunk->all())
            ->groupBy('query', 'page')
            ->selectRaw('`query`, page, SUM(clicks) as clicks, SUM(impressions) as impressions, SUM(position * impressions) / NULLIF(SUM(impressions), 0) as position')
            ->get()
            ->map(fn ($row): array => ['query' => $row->query, 'page' => $this->path($row->page), 'clicks' => (int) $row->clicks, 'impressions' => (int) $row->impressions, 'position' => (float) $row->position]));

        return $this->mergeFragments($rows)
            ->groupBy('query')
            ->map(fn (Collection $pages): Collection => $pages->sortBy([['clicks', 'desc'], ['impressions', 'desc']])->values());
    }

    /**
     * Sum rows of the same query and page once fragments are dropped, with
     * the position weighted by impressions.
     *
     * @param  Collection<int, array{query: string, page: string, clicks: int, impressions: int, position: float}>  $rows
     * @return Collection<int, array{query: string, page: string, clicks: int, impressions: int, position: float}>
     */
    private function mergeFragments(Collection $rows): Collection
    {
        return $rows->groupBy(fn (array $row): string => $row['query']."\0".$row['page'])
            ->map(function (Collection $same): array {
                $impressions = $same->sum('impressions');

                return [
                    'query' => $same->first()['query'],
                    'page' => $same->first()['page'],
                    'clicks' => $same->sum('clicks'),
                    'impressions' => $impressions,
                    'position' => $impressions > 0 ? round($same->sum(fn (array $row): float => $row['position'] * $row['impressions']) / $impressions, 1) : 0.0,
                ];
            })
            ->values();
    }

    /**
     * @param  array{page: ?string, query_contains: ?string}  $filters
     */
    private function rows(GaProperty $property, Carbon $from, Carbon $to, array $filters): Builder
    {
        return PropertySearchQuery::query()
            ->select('query')
            ->where('ga_property_id', $property->id)
            ->whereNotNull('page')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($filters['page'], fn ($query, string $path) => $query->whereRaw("page LIKE ? ESCAPE '!'", ['%://%'.$this->escapeLike($path).'%']))
            ->when($filters['query_contains'], fn ($query, string $text) => $query->whereRaw("`query` LIKE ? ESCAPE '!'", ['%'.$this->escapeLike($text).'%']));
    }

    /**
     * Path of a full URL or path, without query string and fragment.
     */
    private function path(?string $url): string
    {
        return '/'.ltrim(parse_url((string) $url, PHP_URL_PATH) ?: '/', '/');
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'property_id' => $schema->integer()
                ->description('The internal ID of the GA4 property. Use list-properties to find it.')
                ->required(),
            'view' => $schema->string()
                ->enum(['cannibalization', 'pages'])
                ->description('cannibalization (default): queries split between competing pages. pages: each page with its top queries.'),
            'from' => $schema->string()
                ->description('Start date (YYYY-MM-DD). Defaults to 90 days before "to".'),
            'to' => $schema->string()
                ->description('End date (YYYY-MM-DD). Defaults to today.'),
            'page' => $schema->string()
                ->description('Only rows whose page path contains this path, e.g. /calcolo/imu/. In the cannibalization view it selects the queries; their competing pages are always shown in full.'),
            'query_contains' => $schema->string()
                ->description('Only queries containing this text.'),
            'min_impressions' => $schema->integer()
                ->description('Cannibalization view: minimum impressions of a query in the range. Defaults to 50.'),
            'min_share' => $schema->integer()
                ->description('Cannibalization view: minimum share (%) of the query impressions a page must hold to count as competing. Defaults to 10.'),
            'limit' => $schema->integer()
                ->description('Queries or pages to return. Defaults to 30, maximum 200.'),
        ];
    }
}

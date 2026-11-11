<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\IncludesAnnotations;
use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Models\PropertySearchQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Get the top Google Search Console queries (30 by default, up to 500) for a GA4 property within a date range, optionally filtered to one page or a URL prefix (e.g. a section of the site) and to queries containing a given text. Clicks and impressions are true totals summed over every day in the range, while CTR (%) and average position are weighted by impressions. Also returns the landing page (full URL) that earned the most clicks for each query. Use this to identify SEO opportunities, low-CTR keywords to optimize, and high-impression queries that could drive more traffic.')]
#[IsReadOnly]
#[IsIdempotent]
class GetPropertySearchQueriesTool extends Tool
{
    use IncludesAnnotations, ResolvesMcpContext;

    /** Queries returned per call by default. */
    private const DEFAULT_LIMIT = 30;

    /** Upper bound for the limit argument. */
    private const MAX_LIMIT = 500;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => 'required|integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'sort_by' => 'nullable|in:clicks,impressions,ctr,position',
            'page' => 'nullable|string|max:2048',
            'page_match' => 'nullable|in:exact,prefix',
            'query_contains' => 'nullable|string|max:255',
            'limit' => 'nullable|integer|min:1|max:'.self::MAX_LIMIT,
        ]);

        $from = $validated['from'] ?? now()->subDays(30)->toDateString();
        $to = $validated['to'] ?? now()->toDateString();
        $sortBy = $validated['sort_by'] ?? 'clicks';

        $limit = $validated['limit'] ?? self::DEFAULT_LIMIT;
        $pageMatch = $validated['page_match'] ?? 'prefix';
        $queryContains = filled($validated['query_contains'] ?? null) ? trim($validated['query_contains']) : null;

        $property = $this->resolveAuthorizedProperty($validated['property_id']);

        $page = filled($validated['page'] ?? null)
            ? $this->resolvePageUrl(trim($validated['page']), $property->website_url)
            : null;

        $filters = fn (Builder $query): Builder => $this->applyFilters($query, $page, $pageMatch, $queryContains);

        $queries = $filters(PropertySearchQuery::where('ga_property_id', $property->id))
            ->whereBetween('date', [$from, $to])
            ->selectRaw('`query`, SUM(clicks) as total_clicks, SUM(impressions) as total_impressions')
            ->selectRaw('SUM(clicks) * 100.0 / NULLIF(SUM(impressions), 0) as avg_ctr')
            ->selectRaw('SUM(position * impressions) * 1.0 / NULLIF(SUM(impressions), 0) as avg_position')
            ->groupBy('query')
            ->orderBy($this->orderColumn($sortBy), $sortBy === 'position' ? 'asc' : 'desc')
            ->limit($limit)
            ->get();

        $topPages = $this->topPagePerQuery($property->id, $from, $to, $queries->pluck('query')->all(), $filters);

        $queries = $queries->map(fn ($row) => [
            'query' => $row->query,
            'page' => $topPages[$row->query] ?? null,
            'total_clicks' => (int) $row->total_clicks,
            'total_impressions' => (int) $row->total_impressions,
            'avg_ctr' => round((float) $row->avg_ctr, 2),
            'avg_position' => round((float) $row->avg_position, 1),
        ]);

        $result = [
            'property' => $property->display_name,
            'from' => $from,
            'to' => $to,
            'sorted_by' => $sortBy,
            'filters' => array_filter([
                'page' => $page,
                'page_match' => $page !== null ? $pageMatch : null,
                'query_contains' => $queryContains,
            ]),
            'count' => $queries->count(),
            'queries' => $queries,
            'annotations' => $this->annotationsBetween($property, $from, $to),
        ];

        return Response::text(json_encode($result, JSON_PRETTY_PRINT));
    }

    private function orderColumn(string $sortBy): string
    {
        return match ($sortBy) {
            'ctr' => 'avg_ctr',
            'position' => 'avg_position',
            default => "total_{$sortBy}",
        };
    }

    /**
     * Turn a site relative path (e.g. "/blog/") into a full URL using the
     * property website, since Search Console pages are stored as full URLs.
     */
    private function resolvePageUrl(string $page, ?string $websiteUrl): string
    {
        if (! str_starts_with($page, '/') || blank($websiteUrl)) {
            return $page;
        }

        $scheme = parse_url($websiteUrl, PHP_URL_SCHEME);
        $host = parse_url($websiteUrl, PHP_URL_HOST);

        if ($scheme === null || $host === null) {
            return $page;
        }

        return "{$scheme}://{$host}{$page}";
    }

    /**
     * @param  Builder<PropertySearchQuery>  $query
     * @return Builder<PropertySearchQuery>
     */
    private function applyFilters(Builder $query, ?string $page, string $pageMatch, ?string $queryContains): Builder
    {
        if ($page !== null) {
            $pageMatch === 'exact'
                ? $query->where('page', $page)
                : $query->whereRaw("page LIKE ? ESCAPE '!'", [$this->escapeLike($page).'%']);
        }

        if ($queryContains !== null) {
            $query->whereRaw("`query` LIKE ? ESCAPE '!'", ['%'.$this->escapeLike($queryContains).'%']);
        }

        return $query;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * Resolve the best performing landing page for each of the given queries,
     * within the same page and query filters as the main result.
     *
     * @param  array<int, string>  $queries
     * @param  \Closure(Builder<PropertySearchQuery>): Builder<PropertySearchQuery>  $filters
     * @return array<string, string|null>
     */
    private function topPagePerQuery(int $propertyId, string $from, string $to, array $queries, \Closure $filters): array
    {
        if ($queries === []) {
            return [];
        }

        return $filters(PropertySearchQuery::where('ga_property_id', $propertyId))
            ->whereBetween('date', [$from, $to])
            ->whereIn('query', $queries)
            ->selectRaw('`query`, page, SUM(clicks) as page_clicks, SUM(impressions) as page_impressions')
            ->groupBy('query', 'page')
            ->orderByDesc('page_clicks')
            ->orderByDesc('page_impressions')
            ->get()
            ->groupBy('query')
            ->map(fn ($rows) => $rows->first()->page)
            ->all();
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
            'from' => $schema->string()
                ->description('Start date (YYYY-MM-DD). Defaults to 30 days ago.'),
            'to' => $schema->string()
                ->description('End date (YYYY-MM-DD). Defaults to today.'),
            'sort_by' => $schema->string()
                ->enum(['clicks', 'impressions', 'ctr', 'position'])
                ->description('Sort queries by this metric. Defaults to clicks. Position sorts ascending (best first).'),
            'page' => $schema->string()
                ->description('Only count rows for this landing page: a full URL (https://example.com/blog/) or a path (/blog/), resolved against the property website. Matched as a URL prefix unless page_match is exact.'),
            'page_match' => $schema->string()
                ->enum(['exact', 'prefix'])
                ->description('How to match page: prefix (default, the page and everything under it) or exact (that URL only).'),
            'query_contains' => $schema->string()
                ->description('Only queries containing this text (case insensitive), e.g. "imu".'),
            'limit' => $schema->integer()
                ->description('Number of queries to return. Defaults to 30, maximum 500.'),
        ];
    }
}

<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Models\PropertySearchQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Get top Google Search Console queries for a GA4 property within a date range. Returns search query, landing page, clicks, impressions, CTR (%), and average position. Use this to identify SEO opportunities, low-CTR keywords to optimize, and high-impression queries that could drive more traffic.')]
#[IsReadOnly]
#[IsIdempotent]
class GetPropertySearchQueriesTool extends Tool
{
    use ResolvesMcpContext;

    /** Queries returned per call. */
    private const QUERY_LIMIT = 30;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => 'required|integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'sort_by' => 'nullable|in:clicks,impressions,ctr,position',
        ]);

        $from = $validated['from'] ?? now()->subDays(30)->toDateString();
        $to = $validated['to'] ?? now()->toDateString();
        $sortBy = $validated['sort_by'] ?? 'clicks';

        $property = $this->resolveAuthorizedProperty($validated['property_id']);

        $queries = PropertySearchQuery::where('ga_property_id', $property->id)
            ->whereBetween('date', [$from, $to])
            ->selectRaw('`query`, SUM(clicks) as total_clicks, SUM(impressions) as total_impressions')
            ->selectRaw('SUM(clicks) * 100.0 / NULLIF(SUM(impressions), 0) as avg_ctr')
            ->selectRaw('SUM(position * impressions) * 1.0 / NULLIF(SUM(impressions), 0) as avg_position')
            ->groupBy('query')
            ->orderBy($this->orderColumn($sortBy), $sortBy === 'position' ? 'asc' : 'desc')
            ->limit(self::QUERY_LIMIT)
            ->get();

        $topPages = $this->topPagePerQuery($property->id, $from, $to, $queries->pluck('query')->all());

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
            'queries' => $queries,
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
     * Resolve the best performing landing page for each of the given queries.
     *
     * @param  array<int, string>  $queries
     * @return array<string, string|null>
     */
    private function topPagePerQuery(int $propertyId, string $from, string $to, array $queries): array
    {
        if ($queries === []) {
            return [];
        }

        return PropertySearchQuery::where('ga_property_id', $propertyId)
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
        ];
    }
}

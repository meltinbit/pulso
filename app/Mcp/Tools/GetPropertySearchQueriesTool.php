<?php

namespace App\Mcp\Tools;

use App\Models\GaProperty;
use App\Models\PropertySnapshotSearchQuery;
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
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => 'required|integer|exists:ga_properties,id',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'sort_by' => 'nullable|in:clicks,impressions,ctr,position',
        ]);

        $from = $validated['from'] ?? now()->subDays(30)->toDateString();
        $to = $validated['to'] ?? now()->toDateString();
        $sortBy = $validated['sort_by'] ?? 'clicks';

        $property = GaProperty::findOrFail($validated['property_id']);

        $snapshotIds = $property->snapshots()
            ->whereBetween('snapshot_date', [$from, $to])
            ->pluck('id');

        $orderColumn = match ($sortBy) {
            'ctr' => 'avg_ctr',
            'position' => 'avg_position',
            default => "total_{$sortBy}",
        };

        $orderDirection = $sortBy === 'position' ? 'asc' : 'desc';

        $queries = PropertySnapshotSearchQuery::whereIn('property_snapshot_id', $snapshotIds)
            ->selectRaw('query, MAX(page) as page, SUM(clicks) as total_clicks, SUM(impressions) as total_impressions, AVG(ctr) as avg_ctr, AVG(position) as avg_position')
            ->groupBy('query')
            ->orderBy($orderColumn, $orderDirection)
            ->limit(30)
            ->get()
            ->map(fn ($q) => [
                'query' => $q->query,
                'page' => $q->page,
                'total_clicks' => (int) $q->total_clicks,
                'total_impressions' => (int) $q->total_impressions,
                'avg_ctr' => round((float) $q->avg_ctr, 2),
                'avg_position' => round((float) $q->avg_position, 1),
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

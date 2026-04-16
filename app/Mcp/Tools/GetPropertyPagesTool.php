<?php

namespace App\Mcp\Tools;

use App\Models\GaProperty;
use App\Models\PropertySnapshotPage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Get top pages for a GA4 property within a date range. Returns page path, title, pageviews, users, bounce rate, avg engagement time, and engagement rate. Use this to identify high-traffic pages with poor engagement or find content opportunities.')]
#[IsReadOnly]
#[IsIdempotent]
class GetPropertyPagesTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => 'required|integer|exists:ga_properties,id',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'sort_by' => 'nullable|in:pageviews,bounce_rate,engagement_rate,users',
        ]);

        $from = $validated['from'] ?? now()->subDays(30)->toDateString();
        $to = $validated['to'] ?? now()->toDateString();
        $sortBy = $validated['sort_by'] ?? 'pageviews';

        $property = GaProperty::findOrFail($validated['property_id']);

        $snapshotIds = $property->snapshots()
            ->whereBetween('snapshot_date', [$from, $to])
            ->pluck('id');

        $orderColumn = match ($sortBy) {
            'bounce_rate' => 'avg_bounce_rate',
            'engagement_rate' => 'avg_engagement_rate',
            default => "total_{$sortBy}",
        };

        $pages = PropertySnapshotPage::whereIn('property_snapshot_id', $snapshotIds)
            ->selectRaw('page_path, MAX(page_title) as page_title, SUM(pageviews) as total_pageviews, SUM(users) as total_users, AVG(bounce_rate) as avg_bounce_rate, AVG(avg_engagement_time) as avg_engagement_time, AVG(engagement_rate) as avg_engagement_rate')
            ->groupBy('page_path')
            ->orderByDesc($orderColumn)
            ->limit(20)
            ->get()
            ->map(fn ($p) => [
                'page_path' => $p->page_path,
                'page_title' => $p->page_title,
                'total_pageviews' => (int) $p->total_pageviews,
                'total_users' => (int) $p->total_users,
                'avg_bounce_rate' => round((float) $p->avg_bounce_rate, 2),
                'avg_engagement_time' => (int) round((float) $p->avg_engagement_time),
                'avg_engagement_rate' => round((float) $p->avg_engagement_rate, 2),
            ]);

        $result = [
            'property' => $property->display_name,
            'from' => $from,
            'to' => $to,
            'sorted_by' => $sortBy,
            'pages' => $pages,
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
                ->enum(['pageviews', 'bounce_rate', 'engagement_rate', 'users'])
                ->description('Sort pages by this metric. Defaults to pageviews.'),
        ];
    }
}

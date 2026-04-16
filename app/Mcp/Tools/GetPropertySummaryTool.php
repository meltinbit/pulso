<?php

namespace App\Mcp\Tools;

use App\Models\GaProperty;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Get a comprehensive analytical summary for a GA4 property. Includes the latest snapshot with engagement metrics, 7-day and 30-day averages, recent anomalies (spikes/drops), top traffic sources, and top pages with per-page bounce rate and entrances. Best tool for getting actionable insights on a single property.')]
#[IsReadOnly]
#[IsIdempotent]
class GetPropertySummaryTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => 'required|integer|exists:ga_properties,id',
        ]);

        $property = GaProperty::findOrFail($validated['property_id']);
        $latest = $property->snapshots()->latest('snapshot_date')->first();

        if (! $latest) {
            return Response::text(json_encode([
                'property' => $property->display_name,
                'message' => 'No snapshots available yet for this property.',
            ], JSON_PRETTY_PRINT));
        }

        $last7 = $property->snapshots()
            ->where('snapshot_date', '>=', now()->subDays(7))
            ->orderBy('snapshot_date')
            ->get();

        $last30 = $property->snapshots()
            ->where('snapshot_date', '>=', now()->subDays(30))
            ->orderBy('snapshot_date')
            ->get();

        $anomalies = $last30->filter(fn ($s) => $s->is_spike || $s->is_drop)
            ->map(fn ($s) => [
                'date' => $s->snapshot_date->toDateString(),
                'type' => $s->is_spike ? 'spike' : 'drop',
                'users' => $s->users,
                'users_delta_wow' => $s->users_delta_wow,
            ])->values();

        $latestSources = $latest->sources()
            ->orderByDesc('sessions')
            ->limit(5)
            ->get()
            ->map(fn ($s) => [
                'source' => $s->source,
                'medium' => $s->medium,
                'sessions' => $s->sessions,
                'users' => $s->users,
            ]);

        $avg = function ($collection, $field) {
            $values = $collection->pluck($field)->filter(fn ($v) => $v !== null);

            return $values->isEmpty() ? null : round($values->avg(), 2);
        };

        $result = [
            'property' => $property->display_name,
            'website_url' => $property->website_url,
            'latest' => [
                'date' => $latest->snapshot_date->toDateString(),
                'users' => $latest->users,
                'sessions' => $latest->sessions,
                'pageviews' => $latest->pageviews,
                'bounce_rate' => $latest->bounce_rate,
                'avg_session_duration' => $latest->avg_session_duration,
                'pages_per_session' => $latest->pages_per_session,
                'engaged_sessions' => $latest->engaged_sessions,
                'engagement_rate' => $latest->engagement_rate,
                'trend' => $latest->trend,
                'trend_score' => $latest->trend_score,
                'users_delta_wow' => $latest->users_delta_wow,
                'sessions_delta_wow' => $latest->sessions_delta_wow,
                'users_delta_30d' => $latest->users_delta_30d,
                'is_spike' => $latest->is_spike,
                'is_drop' => $latest->is_drop,
                'is_stall' => $latest->is_stall,
            ],
            'averages_7d' => [
                'users' => $avg($last7, 'users'),
                'sessions' => $avg($last7, 'sessions'),
                'pageviews' => $avg($last7, 'pageviews'),
                'bounce_rate' => $avg($last7, 'bounce_rate'),
                'engagement_rate' => $avg($last7, 'engagement_rate'),
            ],
            'averages_30d' => [
                'users' => $avg($last30, 'users'),
                'sessions' => $avg($last30, 'sessions'),
                'pageviews' => $avg($last30, 'pageviews'),
                'bounce_rate' => $avg($last30, 'bounce_rate'),
                'engagement_rate' => $avg($last30, 'engagement_rate'),
            ],
            'trend_distribution_30d' => $last30->groupBy('trend')
                ->map(fn ($group) => $group->count()),
            'anomalies' => $anomalies,
            'top_sources_latest' => $latestSources,
            'top_search_queries_latest' => $latest->searchQueries()
                ->orderByDesc('clicks')
                ->limit(10)
                ->get()
                ->map(fn ($q) => [
                    'query' => $q->query,
                    'page' => $q->page,
                    'clicks' => $q->clicks,
                    'impressions' => $q->impressions,
                    'ctr' => $q->ctr,
                    'position' => $q->position,
                ]),
            'top_pages_latest' => $latest->pages()
                ->orderByDesc('pageviews')
                ->limit(10)
                ->get()
                ->map(fn ($p) => [
                    'page_path' => $p->page_path,
                    'page_title' => $p->page_title,
                    'pageviews' => $p->pageviews,
                    'users' => $p->users,
                    'bounce_rate' => $p->bounce_rate,
                    'avg_engagement_time' => $p->avg_engagement_time,
                    'engagement_rate' => $p->engagement_rate,
                ]),
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
        ];
    }
}

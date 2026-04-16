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

#[Description('Get daily snapshots for a GA4 property within a date range. Returns metrics (users, sessions, pageviews, bounce rate, avg session duration), week-over-week and 30-day deltas, trend category, trend score, and anomaly flags.')]
#[IsReadOnly]
#[IsIdempotent]
class GetPropertySnapshotsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => 'required|integer|exists:ga_properties,id',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $from = $validated['from'] ?? now()->subDays(30)->toDateString();
        $to = $validated['to'] ?? now()->toDateString();

        $property = GaProperty::findOrFail($validated['property_id']);

        $snapshots = $property->snapshots()
            ->whereBetween('snapshot_date', [$from, $to])
            ->orderBy('snapshot_date')
            ->get()
            ->map(fn ($s) => [
                'date' => $s->snapshot_date->toDateString(),
                'users' => $s->users,
                'sessions' => $s->sessions,
                'pageviews' => $s->pageviews,
                'bounce_rate' => $s->bounce_rate,
                'avg_session_duration' => $s->avg_session_duration,
                'users_delta_wow' => $s->users_delta_wow,
                'sessions_delta_wow' => $s->sessions_delta_wow,
                'pageviews_delta_wow' => $s->pageviews_delta_wow,
                'bounce_delta_wow' => $s->bounce_delta_wow,
                'users_delta_30d' => $s->users_delta_30d,
                'sessions_delta_30d' => $s->sessions_delta_30d,
                'trend' => $s->trend,
                'trend_score' => $s->trend_score,
                'is_spike' => $s->is_spike,
                'is_drop' => $s->is_drop,
                'is_stall' => $s->is_stall,
            ]);

        $result = [
            'property' => $property->display_name,
            'website_url' => $property->website_url,
            'from' => $from,
            'to' => $to,
            'count' => $snapshots->count(),
            'snapshots' => $snapshots,
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
        ];
    }
}

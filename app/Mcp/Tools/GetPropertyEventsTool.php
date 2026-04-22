<?php

namespace App\Mcp\Tools;

use App\Models\GaProperty;
use App\Models\PropertySnapshotEvent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Get GA4 events (standard and custom) for a property within a date range. Returns event name, total event count, and total users that triggered it, aggregated across the range. Use this to inspect custom events like calcolo_eseguito or feedback_calcolatore, or standard events like page_view, session_start, scroll. Optionally filter by a specific event_name to trace a single event over time.')]
#[IsReadOnly]
#[IsIdempotent]
class GetPropertyEventsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => 'required|integer|exists:ga_properties,id',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'event_name' => 'nullable|string|max:255',
            'sort_by' => 'nullable|in:event_count,total_users',
        ]);

        $from = $validated['from'] ?? now()->subDays(30)->toDateString();
        $to = $validated['to'] ?? now()->toDateString();
        $sortBy = $validated['sort_by'] ?? 'event_count';
        $eventName = $validated['event_name'] ?? null;

        $property = GaProperty::findOrFail($validated['property_id']);

        $snapshotIds = $property->snapshots()
            ->whereBetween('snapshot_date', [$from, $to])
            ->pluck('id');

        $orderColumn = "total_{$sortBy}";

        $query = PropertySnapshotEvent::whereIn('property_snapshot_id', $snapshotIds)
            ->selectRaw('event_name, SUM(event_count) as total_event_count, SUM(total_users) as total_total_users')
            ->groupBy('event_name')
            ->orderByDesc($orderColumn)
            ->limit(50);

        if ($eventName !== null) {
            $query->where('event_name', $eventName);
        }

        $events = $query->get()->map(fn ($e) => [
            'event_name' => $e->event_name,
            'total_event_count' => (int) $e->total_event_count,
            'total_users' => (int) $e->total_total_users,
        ]);

        $result = [
            'property' => $property->display_name,
            'from' => $from,
            'to' => $to,
            'sorted_by' => $sortBy,
            'event_name_filter' => $eventName,
            'events' => $events,
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
            'event_name' => $schema->string()
                ->description('Optional: filter to a single event name (e.g. "calcolo_eseguito"). Omit to list all events.'),
            'sort_by' => $schema->string()
                ->enum(['event_count', 'total_users'])
                ->description('Sort events by this metric. Defaults to event_count.'),
        ];
    }
}

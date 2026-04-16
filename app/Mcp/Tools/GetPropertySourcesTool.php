<?php

namespace App\Mcp\Tools;

use App\Models\GaProperty;
use App\Models\PropertySnapshotSource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Get aggregated traffic sources for a GA4 property within a date range. Returns total sessions and users grouped by source and medium, sorted by sessions descending.')]
#[IsReadOnly]
#[IsIdempotent]
class GetPropertySourcesTool extends Tool
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

        $snapshotIds = $property->snapshots()
            ->whereBetween('snapshot_date', [$from, $to])
            ->pluck('id');

        $sources = PropertySnapshotSource::whereIn('property_snapshot_id', $snapshotIds)
            ->selectRaw('source, medium, SUM(sessions) as total_sessions, SUM(users) as total_users')
            ->groupBy('source', 'medium')
            ->orderByDesc('total_sessions')
            ->get()
            ->map(fn ($s) => [
                'source' => $s->source,
                'medium' => $s->medium,
                'total_sessions' => (int) $s->total_sessions,
                'total_users' => (int) $s->total_users,
            ]);

        $result = [
            'property' => $property->display_name,
            'from' => $from,
            'to' => $to,
            'sources' => $sources,
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

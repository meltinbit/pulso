<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Models\PropertyBingSearchStat;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Get Bing search queries or pages (dimension) for a property: clicks, impressions, CTR (%) and average position weighted by impressions, summed over the range. Bing publishes these per week (Friday to Thursday), so from/to are widened to the whole weeks that contain them and the answer states the period actually covered. Bing also feeds Copilot, DuckDuckGo and several AI search engines, so it is a GEO signal too. Filter with contains (text in the query or URL). Output is columnar (cols + rows).')]
#[IsReadOnly]
#[IsIdempotent]
class GetPropertyBingSearchTool extends Tool
{
    use ResolvesMcpContext;

    private const MAX_LIMIT = 500;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => 'required|integer',
            'dimension' => 'nullable|in:query,page',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'contains' => 'nullable|string|max:255',
            'sort_by' => 'nullable|in:clicks,impressions,ctr,position',
            'limit' => 'nullable|integer|min:1|max:'.self::MAX_LIMIT,
        ]);

        $property = $this->resolveAuthorizedProperty($validated['property_id']);

        if ($property->bing_site_url === null) {
            return Response::error("{$property->display_name} non è collegata a un sito Bing: salva la API key e collega il sito in Impostazioni → Bing del pannello di Pulso.");
        }

        $dimension = $validated['dimension'] ?? PropertyBingSearchStat::DIMENSION_QUERY;
        $from = Carbon::parse($validated['from'] ?? now()->subDays(30))->toDateString();
        $to = Carbon::parse($validated['to'] ?? now())->toDateString();
        $sortBy = $validated['sort_by'] ?? 'clicks';
        $contains = filled($validated['contains'] ?? null) ? trim($validated['contains']) : null;

        $weeks = PropertyBingSearchStat::query()
            ->where('ga_property_id', $property->id)
            ->where('dimension', $dimension)
            ->whereBetween('week_start', [Carbon::parse($from)->subDays(6)->toDateString(), $to])
            ->when($contains, fn ($query, string $text) => $query->where('value', 'like', '%'.addcslashes($text, '%_\\').'%'));

        $period = (clone $weeks)->selectRaw('MIN(week_start) as first_week, MAX(week_start) as last_week')->first();

        $rows = (clone $weeks)
            ->select('value')
            ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions')
            ->selectRaw('SUM(clicks) * 100.0 / NULLIF(SUM(impressions), 0) as ctr')
            ->selectRaw('SUM(avg_impression_position * impressions) * 1.0 / NULLIF(SUM(CASE WHEN avg_impression_position IS NOT NULL THEN impressions END), 0) as position')
            ->groupBy('value')
            ->orderBy($sortBy, $sortBy === 'position' ? 'asc' : 'desc')
            ->limit($validated['limit'] ?? 30)
            ->get()
            ->map(fn ($row): array => [
                $row->value,
                (int) $row->clicks,
                (int) $row->impressions,
                round((float) $row->ctr, 2),
                $row->position === null ? null : round((float) $row->position, 1),
            ]);

        return Response::text(json_encode([
            'property' => $property->display_name,
            'dimension' => $dimension,
            'requested' => [$from, $to],
            'covered' => $period?->first_week
                ? [Carbon::parse($period->first_week)->toDateString(), Carbon::parse($period->last_week)->addDays(6)->toDateString()]
                : null,
            'contains' => $contains,
            'sorted_by' => $sortBy,
            'cols' => [$dimension, 'clicks', 'impressions', 'ctr', 'position'],
            'rows' => $rows,
        ]));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'property_id' => $schema->integer()
                ->description('The internal ID of the property. Use list-properties to find it.')
                ->required(),
            'dimension' => $schema->string()
                ->enum(['query', 'page'])
                ->description('query (default) for search queries, page for landing page URLs.'),
            'from' => $schema->string()
                ->description('Start date (YYYY-MM-DD). Defaults to 30 days ago; widened to the start of its Bing week.'),
            'to' => $schema->string()
                ->description('End date (YYYY-MM-DD). Defaults to today; widened to the end of its Bing week.'),
            'contains' => $schema->string()
                ->description('Only rows whose query or URL contains this text, e.g. "imu" or "/blog/".'),
            'sort_by' => $schema->string()
                ->enum(['clicks', 'impressions', 'ctr', 'position'])
                ->description('Sort metric. Defaults to clicks. Position sorts ascending (best first).'),
            'limit' => $schema->integer()
                ->description('Number of rows. Defaults to 30, maximum 500.'),
        ];
    }
}

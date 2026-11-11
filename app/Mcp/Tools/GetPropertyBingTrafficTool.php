<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\IncludesAnnotations;
use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Models\GaProperty;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Get Bing search traffic and crawl health for a property: total clicks, impressions and CTR in the range with % change against the previous period of equal length, a weekly series (columnar), and crawl stats from Bing Webmaster Tools: pages in the Bing index at the start and end of the range, pages crawled, crawl errors, 4xx and 5xx responses and robots.txt blocks. Bing data lags about 3 days. Bing feeds Copilot, DuckDuckGo and several AI search engines, so index coverage here is also a GEO signal.')]
#[IsReadOnly]
#[IsIdempotent]
class GetPropertyBingTrafficTool extends Tool
{
    use IncludesAnnotations, ResolvesMcpContext;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => 'required|integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $property = $this->resolveAuthorizedProperty($validated['property_id']);

        if ($property->bing_site_url === null) {
            return Response::error("{$property->display_name} non è collegata a un sito Bing: salva la API key e collega il sito in Impostazioni → Bing del pannello di Pulso.");
        }

        $from = Carbon::parse($validated['from'] ?? now()->subDays(30))->startOfDay();
        $to = Carbon::parse($validated['to'] ?? now())->startOfDay();
        $days = $from->diffInDays($to) + 1;
        $previousFrom = $from->copy()->subDays($days);
        $previousTo = $from->copy()->subDay();

        $current = $this->totals($property, $from, $to);
        $previous = $this->totals($property, $previousFrom, $previousTo);

        $weekly = $property->bingTraffic()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->get()
            ->groupBy(fn ($row): string => $row->date->copy()->startOfWeek()->toDateString())
            ->map(fn ($rows, string $week): array => [$week, $rows->sum('clicks'), $rows->sum('impressions')])
            ->values();

        return Response::text(json_encode([
            'property' => $property->display_name,
            'bing_site' => $property->bing_site_url,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'last_day_with_data' => $property->bingTraffic()->max('date') ? Carbon::parse($property->bingTraffic()->max('date'))->toDateString() : null,
            'totals' => $current,
            'previous' => $previous + ['from' => $previousFrom->toDateString(), 'to' => $previousTo->toDateString()],
            'change_pct' => [
                'clicks' => $this->change($current['clicks'], $previous['clicks']),
                'impressions' => $this->change($current['impressions'], $previous['impressions']),
            ],
            'weekly' => ['cols' => ['week_start', 'clicks', 'impressions'], 'rows' => $weekly],
            'crawl' => $this->crawl($property, $from, $to),
            'annotations' => $this->annotationsBetween($property, $from, $to),
        ]));
    }

    /**
     * @return array{clicks: int, impressions: int, ctr: ?float}
     */
    private function totals(GaProperty $property, Carbon $from, Carbon $to): array
    {
        $row = $property->bingTraffic()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(clicks), 0) as clicks, COALESCE(SUM(impressions), 0) as impressions')
            ->first();

        $clicks = (int) $row->clicks;
        $impressions = (int) $row->impressions;

        return [
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : null,
        ];
    }

    /**
     * @return array<string, int|string|null>|null
     */
    private function crawl(GaProperty $property, Carbon $from, Carbon $to): ?array
    {
        $rows = $property->bingCrawlStats()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        return [
            'in_index_start' => $rows->first()->in_index,
            'in_index_end' => $rows->last()->in_index,
            'in_index_date' => $rows->last()->date->toDateString(),
            'crawled_pages' => $rows->sum('crawled_pages'),
            'crawl_errors' => $rows->sum('crawl_errors'),
            'code_4xx' => $rows->sum('code_4xx'),
            'code_5xx' => $rows->sum('code_5xx'),
            'blocked_by_robots_txt' => $rows->sum('blocked_by_robots_txt'),
        ];
    }

    private function change(int $current, int $previous): ?float
    {
        return $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null;
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
            'from' => $schema->string()
                ->description('Start date (YYYY-MM-DD). Defaults to 30 days ago.'),
            'to' => $schema->string()
                ->description('End date (YYYY-MM-DD). Defaults to today.'),
        ];
    }
}

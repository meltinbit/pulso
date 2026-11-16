<?php

namespace App\Mcp\Tools;

use App\Exceptions\SearchConsoleApiException;
use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Services\SearchConsoleService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Live Google Search Console Search Analytics query, for what get-property-search-queries (stored web query/page rows) does not cover: breakdowns by country, device, search appearance (rich results, AMP, video...) or hour; search types image, video, news, discover and googleNews; fresh data of the last 2-3 days (data_state all, the default) or hourly data of the last days (dimension hour). Filters on query or page text, country and device. Returns the totals of the range and up to 1000 rows (columnar: cols + rows), sorted by clicks. CTR is a percentage, position is the average position. Discover and googleNews do not support the query dimension.')]
#[IsReadOnly]
#[IsIdempotent]
class QuerySearchAnalyticsTool extends Tool
{
    use ResolvesMcpContext;

    private const DIMENSIONS = ['date', 'query', 'page', 'country', 'device', 'searchAppearance', 'hour'];

    private const TYPES = ['web', 'image', 'video', 'news', 'discover', 'googleNews'];

    public function __construct(
        private SearchConsoleService $searchConsole,
    ) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => 'required|integer',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'dimensions' => 'nullable|array|max:4',
            'dimensions.*' => 'string|in:'.implode(',', self::DIMENSIONS),
            'type' => 'nullable|in:'.implode(',', self::TYPES),
            'data_state' => 'nullable|in:final,all',
            'query_contains' => 'nullable|string|max:200',
            'page_contains' => 'nullable|string|max:500',
            'country' => 'nullable|string|size:3',
            'device' => 'nullable|in:desktop,mobile,tablet',
            'search_appearance' => 'nullable|string|max:100',
            'aggregation' => 'nullable|in:auto,byPage,byProperty',
            'limit' => 'nullable|integer|min:1|max:1000',
            'start_row' => 'nullable|integer|min:0',
        ]);

        $property = $this->resolveAuthorizedProperty($validated['property_id']);
        $to = Carbon::parse($validated['to'] ?? now()->toDateString());
        $from = Carbon::parse($validated['from'] ?? $to->copy()->subDays(27)->toDateString());
        $dimensions = array_values(array_unique($validated['dimensions'] ?? ['query']));
        $hourly = in_array('hour', $dimensions, true);

        $base = array_filter([
            'startDate' => $from->toDateString(),
            'endDate' => $to->toDateString(),
            'type' => $validated['type'] ?? 'web',
            'dataState' => $hourly ? 'hourly_all' : ($validated['data_state'] ?? 'all'),
            'aggregationType' => $validated['aggregation'] ?? 'auto',
            'dimensionFilterGroups' => $this->filters($validated),
        ]);

        try {
            $totals = $this->searchConsole->querySearchAnalytics($property, $base)[0] ?? null;
            $rows = $this->searchConsole->querySearchAnalytics($property, $base + [
                'dimensions' => $dimensions,
                'rowLimit' => $validated['limit'] ?? 100,
                'startRow' => $validated['start_row'] ?? 0,
            ]);
        } catch (SearchConsoleApiException $e) {
            return Response::error($e->getMessage());
        }

        return Response::text(json_encode([
            'property' => $property->display_name,
            'from' => $base['startDate'],
            'to' => $base['endDate'],
            'type' => $base['type'],
            'data_state' => $base['dataState'],
            'totals' => $totals === null ? ['clicks' => 0, 'impressions' => 0, 'ctr' => null, 'position' => null] : [
                'clicks' => $totals['clicks'],
                'impressions' => $totals['impressions'],
                'ctr' => $totals['ctr'],
                'position' => $totals['position'],
            ],
            'cols' => [...$dimensions, 'clicks', 'impressions', 'ctr', 'position'],
            'rows' => array_map(fn (array $row): array => [
                ...$row['keys'], $row['clicks'], $row['impressions'], $row['ctr'], $row['position'],
            ], $rows),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<array{filters: list<array{dimension: string, operator: string, expression: string}>}>
     */
    private function filters(array $validated): array
    {
        $filters = array_values(array_filter([
            isset($validated['query_contains']) ? ['dimension' => 'query', 'operator' => 'contains', 'expression' => $validated['query_contains']] : null,
            isset($validated['page_contains']) ? ['dimension' => 'page', 'operator' => 'contains', 'expression' => $validated['page_contains']] : null,
            isset($validated['country']) ? ['dimension' => 'country', 'operator' => 'equals', 'expression' => strtolower($validated['country'])] : null,
            isset($validated['device']) ? ['dimension' => 'device', 'operator' => 'equals', 'expression' => strtoupper($validated['device'])] : null,
            isset($validated['search_appearance']) ? ['dimension' => 'searchAppearance', 'operator' => 'equals', 'expression' => $validated['search_appearance']] : null,
        ]));

        return $filters === [] ? [] : [['filters' => $filters]];
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
            'from' => $schema->string()->description('Start date (YYYY-MM-DD). Defaults to 27 days before to.'),
            'to' => $schema->string()->description('End date (YYYY-MM-DD). Defaults to today.'),
            'dimensions' => $schema->array()
                ->items($schema->string()->enum(self::DIMENSIONS))
                ->description('Up to 4 of date, query, page, country, device, searchAppearance, hour. Defaults to [query]. hour returns hourly data of the last days only.'),
            'type' => $schema->string()->enum(self::TYPES)->description('Search type. Defaults to web.'),
            'data_state' => $schema->string()->enum(['final', 'all'])->description('all (default) includes the fresh, not yet final data of the last days; final only consolidated days.'),
            'query_contains' => $schema->string()->description('Only queries containing this text.'),
            'page_contains' => $schema->string()->description('Only pages whose URL contains this text, e.g. /blog/.'),
            'country' => $schema->string()->description('ISO 3166-1 alpha-3 country code, e.g. ita, usa.'),
            'device' => $schema->string()->enum(['desktop', 'mobile', 'tablet'])->description('Only this device.'),
            'search_appearance' => $schema->string()->description('Only this search appearance, as returned by the searchAppearance dimension.'),
            'aggregation' => $schema->string()->enum(['auto', 'byPage', 'byProperty'])->description('How Google aggregates results. Defaults to auto.'),
            'limit' => $schema->integer()->description('Number of rows. Defaults to 100, maximum 1000.'),
            'start_row' => $schema->integer()->description('Offset for paging through more rows. Defaults to 0.'),
        ];
    }
}

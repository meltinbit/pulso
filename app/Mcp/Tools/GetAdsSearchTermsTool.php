<?php

namespace App\Mcp\Tools;

use App\Exceptions\GoogleAdsApiException;
use App\Mcp\Tools\Concerns\FormatsAdsReport;
use App\Services\GoogleAdsReportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Google Ads search terms report: the real queries that triggered the ads, summed across ad groups, with cost, clicks, impressions, conversions, conversion value, CPA and whether the term is already a keyword (ADDED) or excluded. Includes a summary with the spend on terms that never converted. Use it to find negative keywords, new keywords and, for SEO, the queries that really convert. Performance Max terms are not included. Defaults to the last 30 complete days; output is columnar.')]
#[IsReadOnly]
#[IsIdempotent]
class GetAdsSearchTermsTool extends Tool
{
    use FormatsAdsReport;

    public function __construct(private GoogleAdsReportService $reports) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|string|max:20',
            'campaign_id' => 'nullable|string|max:20',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'only_converting' => 'nullable|boolean',
            'min_clicks' => 'nullable|integer|min:0',
            'contains' => 'nullable|string|max:255',
            'sort_by' => 'nullable|in:cost,clicks,impressions,conversions,cpa',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        [$from, $to] = $this->adsRange($validated);

        try {
            $customerId = $this->reports->resolveCustomerId($validated['customer_id'] ?? null);
            $terms = collect($this->reports->searchTerms($customerId, $from, $to, $validated['campaign_id'] ?? null));
        } catch (GoogleAdsApiException $e) {
            return Response::error($e->getMessage());
        }

        $summary = [
            'terms' => $terms->count(),
            'cost' => round($terms->sum('cost'), 2),
            'conversions' => round($terms->sum('conversions'), 2),
            'cost_without_conversions' => round($terms->where('conversions', 0)->sum('cost'), 2),
        ];

        $sortBy = $validated['sort_by'] ?? 'cost';
        $contains = filled($validated['contains'] ?? null) ? mb_strtolower(trim($validated['contains'])) : null;

        $rows = $terms
            ->when($validated['only_converting'] ?? false, fn ($rows) => $rows->where('conversions', '>', 0))
            ->when(isset($validated['min_clicks']), fn ($rows) => $rows->where('clicks', '>=', $validated['min_clicks']))
            ->when($contains, fn ($rows) => $rows->filter(fn (array $term): bool => str_contains(mb_strtolower($term['term']), $contains)))
            ->map(fn (array $term): array => $term + ['cpa' => $this->derived($term)['cpa']])
            ->when(
                $sortBy === 'cpa',
                fn ($rows) => $rows->sortBy(fn (array $term): float => $term['cpa'] ?? INF),
                fn ($rows) => $rows->sortByDesc($sortBy),
            )
            ->take($validated['limit'] ?? 50)
            ->map(fn (array $term): array => [
                $term['term'], $term['status'], $term['cost'], $term['clicks'], $term['impressions'],
                $term['conversions'], $term['conversion_value'], $term['cpa'],
            ])
            ->values();

        return Response::text(json_encode([
            'customer_id' => $customerId,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'summary' => $summary,
            'sorted_by' => $sortBy,
            'cols' => ['term', 'status', 'cost', 'clicks', 'impressions', 'conversions', 'conversion_value', 'cpa'],
            'rows' => $rows,
        ]));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'customer_id' => $schema->string()
                ->description('Google Ads account ID. Defaults to the configured account; see list-ads-accounts.'),
            'campaign_id' => $schema->string()
                ->description('Only this campaign (ID from get-ads-campaigns).'),
            'from' => $schema->string()
                ->description('Start date (YYYY-MM-DD). Defaults to 30 days before "to".'),
            'to' => $schema->string()
                ->description('End date (YYYY-MM-DD). Defaults to yesterday.'),
            'only_converting' => $schema->boolean()
                ->description('Only terms with at least one conversion.'),
            'min_clicks' => $schema->integer()
                ->description('Only terms with at least this many clicks.'),
            'contains' => $schema->string()
                ->description('Only terms containing this text.'),
            'sort_by' => $schema->string()
                ->enum(['cost', 'clicks', 'impressions', 'conversions', 'cpa'])
                ->description('Sort metric. Defaults to cost; cpa sorts ascending (cheapest first, terms without conversions last).'),
            'limit' => $schema->integer()
                ->description('Rows to return. Defaults to 50, maximum 500.'),
        ];
    }
}

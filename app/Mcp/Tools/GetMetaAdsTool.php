<?php

namespace App\Mcp\Tools;

use App\Exceptions\MetaAdsApiException;
use App\Mcp\Tools\Concerns\FormatsMetaReport;
use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Services\MetaAdsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Meta (Facebook/Instagram) performance per ad or per ad set, to compare creatives and audiences: campaign, ad set, ad, cost, reach, impressions, frequency, link clicks, CTR (%), CPC, CPM, conversions, CPA and ROAS. Filter to one campaign with campaign_id. Defaults to ads over the last 30 complete days, sorted by cost; output is columnar.')]
#[IsReadOnly]
#[IsIdempotent]
class GetMetaAdsTool extends Tool
{
    use FormatsMetaReport, ResolvesMcpContext;

    public function __construct(private MetaAdsService $metaAds) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate($this->metaCommonRules() + [
            'level' => 'nullable|in:ad,adset',
            'campaign_id' => 'nullable|string|max:30',
            'sort_by' => 'nullable|in:cost,conversions,cpa,ctr,roas',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);
        $user = $this->currentUser();
        $level = $validated['level'] ?? 'ad';

        [$from, $to] = $this->adsRange($validated);

        try {
            $accountId = $this->metaAds->resolveAccountId($user, $validated['account_id'] ?? null);
            $rows = $this->metaAds->insights($user, $accountId, $level, $from, $to, [], $validated['campaign_id'] ?? null);
        } catch (MetaAdsApiException $e) {
            return Response::error($e->getMessage());
        }

        $action = $this->conversionAction($rows, $validated['conversion_action'] ?? null);
        $sortBy = $validated['sort_by'] ?? 'cost';
        $metrics = collect($rows)->map(fn (array $row): array => $row['attributes'] + $this->metaMetrics($row, $action));

        return Response::text(json_encode([
            'account_id' => $accountId,
            'level' => $level,
            'currency' => $rows[0]['currency'] ?? null,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'conversion_action' => $action,
            'totals' => $this->metaTotals($metrics),
            'sorted_by' => $sortBy,
            'cols' => $level === 'ad'
                ? ['campaign', 'adset', 'ad_id', 'ad', 'cost', 'reach', 'impressions', 'frequency', 'clicks', 'ctr', 'cpc', 'cpm', 'conversions', 'cpa', 'roas']
                : ['campaign', 'adset_id', 'adset', 'cost', 'reach', 'impressions', 'frequency', 'clicks', 'ctr', 'cpc', 'cpm', 'conversions', 'cpa', 'roas'],
            'rows' => $metrics
                ->when(
                    $sortBy === 'cpa',
                    fn ($rows) => $rows->sortBy(fn (array $row): float => $row['cpa'] ?? INF),
                    fn ($rows) => $rows->sortByDesc(fn (array $row): float => (float) ($row[$sortBy] ?? 0)),
                )
                ->take($validated['limit'] ?? 50)
                ->map(fn (array $row): array => array_merge(
                    $level === 'ad'
                        ? [$row['campaign_name'] ?? null, $row['adset_name'] ?? null, $row['ad_id'] ?? null, $row['ad_name'] ?? null]
                        : [$row['campaign_name'] ?? null, $row['adset_id'] ?? null, $row['adset_name'] ?? null],
                    [$row['cost'], $row['reach'], $row['impressions'], $row['frequency'], $row['clicks'], $row['ctr'], $row['cpc'], $row['cpm'], $row['conversions'], $row['cpa'], $row['roas']],
                ))
                ->values(),
        ]));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->metaCommonSchema($schema) + [
            'level' => $schema->string()->enum(['ad', 'adset'])
                ->description('ad (default) compares creatives, adset compares audiences and placements set per ad set.'),
            'campaign_id' => $schema->string()
                ->description('Only this campaign (ID from get-meta-campaigns).'),
            'sort_by' => $schema->string()->enum(['cost', 'conversions', 'cpa', 'ctr', 'roas'])
                ->description('Defaults to cost. cpa sorts cheapest first, rows without conversions last.'),
            'limit' => $schema->integer()
                ->description('Rows to return. Defaults to 50, maximum 200.'),
        ];
    }
}

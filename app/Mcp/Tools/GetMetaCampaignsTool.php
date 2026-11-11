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

#[Description('Meta (Facebook/Instagram) campaign performance for a date range: per campaign objective, cost, reach, impressions, frequency, link clicks, CTR (%), CPC, CPM, conversions, conversion value, CPA and ROAS, plus account totals compared with the previous period of equal length. Conversions are one Meta action type (see conversion_action); available_actions lists the account\'s actions so you can pick another. Attribution follows the ad set settings, as in Ads Manager. Defaults to the last 30 complete days; amounts in the account currency; output is columnar, sorted by cost.')]
#[IsReadOnly]
#[IsIdempotent]
class GetMetaCampaignsTool extends Tool
{
    use FormatsMetaReport, ResolvesMcpContext;

    public function __construct(private MetaAdsService $metaAds) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate($this->metaCommonRules());
        $user = $this->currentUser();

        [$from, $to] = $this->adsRange($validated);
        $days = $from->diffInDays($to) + 1;

        try {
            $accountId = $this->metaAds->resolveAccountId($user, $validated['account_id'] ?? null);
            $account = $this->metaAds->insights($user, $accountId, 'account', $from, $to);
            $previous = $this->metaAds->insights($user, $accountId, 'account', $from->copy()->subDays($days), $from->copy()->subDay());
            $campaigns = $this->metaAds->insights($user, $accountId, 'campaign', $from, $to);
        } catch (MetaAdsApiException $e) {
            return Response::error($e->getMessage());
        }

        $action = $this->conversionAction($account, $validated['conversion_action'] ?? null);
        $totals = $account === [] ? $this->metaTotals([]) : $this->metaMetrics($account[0], $action);
        $previousTotals = $previous === [] ? $this->metaTotals([]) : $this->metaMetrics($previous[0], $action);

        return Response::text(json_encode([
            'account_id' => $accountId,
            'currency' => $account[0]['currency'] ?? $campaigns[0]['currency'] ?? null,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'conversion_action' => $action,
            'available_actions' => array_slice($this->actionTotals($account), 0, 10, true),
            'totals' => $totals,
            'previous' => $previousTotals,
            'change_pct' => [
                'cost' => $this->percentChange($totals['cost'], $previousTotals['cost']),
                'conversions' => $this->percentChange($totals['conversions'], $previousTotals['conversions']),
                'cpa' => $totals['cpa'] !== null && $previousTotals['cpa'] !== null ? $this->percentChange($totals['cpa'], $previousTotals['cpa']) : null,
            ],
            'cols' => ['id', 'name', 'objective', 'cost', 'reach', 'impressions', 'frequency', 'clicks', 'ctr', 'cpc', 'cpm', 'conversions', 'conversion_value', 'cpa', 'roas'],
            'rows' => collect($campaigns)
                ->map(fn (array $row): array => $row['attributes'] + $this->metaMetrics($row, $action))
                ->sortByDesc('cost')
                ->map(fn (array $row): array => [
                    $row['campaign_id'] ?? null, $row['campaign_name'] ?? null, $row['objective'] ?? null,
                    $row['cost'], $row['reach'], $row['impressions'], $row['frequency'], $row['clicks'],
                    $row['ctr'], $row['cpc'], $row['cpm'], $row['conversions'], $row['conversion_value'], $row['cpa'], $row['roas'],
                ])
                ->values(),
        ]));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->metaCommonSchema($schema);
    }
}

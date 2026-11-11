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

#[Description('Google Ads campaign performance for a date range: per campaign status, channel, cost, clicks, impressions, CTR (%), CPC, conversions, conversion value, CPA and ROAS, plus account totals compared with the previous period of equal length. Defaults to the last 30 complete days. Amounts are in the account currency. Output is columnar (cols + rows), sorted by cost.')]
#[IsReadOnly]
#[IsIdempotent]
class GetAdsCampaignsTool extends Tool
{
    use FormatsAdsReport;

    public function __construct(private GoogleAdsReportService $reports) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|string|max:20',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        [$from, $to] = $this->adsRange($validated);
        $days = $from->diffInDays($to) + 1;

        try {
            $customerId = $this->reports->resolveCustomerId($validated['customer_id'] ?? null);
            $current = $this->reports->campaigns($customerId, $from, $to);
            $previous = $this->reports->campaigns($customerId, $from->copy()->subDays($days), $from->copy()->subDay());
        } catch (GoogleAdsApiException $e) {
            return Response::error($e->getMessage());
        }

        $campaigns = collect($current['campaigns'])->sortByDesc('cost')->values();
        $totals = $this->totals($current['campaigns']);
        $previousTotals = $this->totals($previous['campaigns']);

        return Response::text(json_encode([
            'customer_id' => $customerId,
            'currency' => $current['currency'],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => $totals,
            'previous' => $previousTotals,
            'change_pct' => [
                'cost' => $this->percentChange($totals['cost'], $previousTotals['cost']),
                'conversions' => $this->percentChange($totals['conversions'], $previousTotals['conversions']),
                'cpa' => $totals['cpa'] !== null && $previousTotals['cpa'] !== null ? $this->percentChange($totals['cpa'], $previousTotals['cpa']) : null,
            ],
            'cols' => ['id', 'name', 'status', 'channel', 'cost', 'clicks', 'impressions', 'ctr', 'cpc', 'conversions', 'conversion_value', 'cpa', 'roas'],
            'rows' => $campaigns->map(function (array $campaign): array {
                $derived = $this->derived($campaign);

                return [
                    $campaign['id'], $campaign['name'], $campaign['status'], $campaign['channel'],
                    $campaign['cost'], $campaign['clicks'], $campaign['impressions'], $derived['ctr'], $derived['cpc'],
                    $campaign['conversions'], $campaign['conversion_value'], $derived['cpa'], $derived['roas'],
                ];
            })->all(),
        ]));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'customer_id' => $schema->string()
                ->description('Google Ads account ID (digits, dashes allowed). Defaults to the configured account; see list-ads-accounts.'),
            'from' => $schema->string()
                ->description('Start date (YYYY-MM-DD). Defaults to 30 days before "to".'),
            'to' => $schema->string()
                ->description('End date (YYYY-MM-DD). Defaults to yesterday.'),
        ];
    }
}

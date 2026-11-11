<?php

namespace App\Mcp\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Shared shape of the Meta Ads tools: which action counts as a conversion,
 * and the metrics derived from Insights rows. Builds on the Google Ads
 * helpers so both platforms answer with the same names.
 */
trait FormatsMetaReport
{
    use FormatsAdsReport;

    /**
     * Action types tried in order when the agent does not choose one: the
     * first one with results is the conversion. Meta repeats the same event
     * under several names (omni_purchase, purchase, offsite_conversion...),
     * so only one is ever counted.
     */
    private const CONVERSION_PRIORITY = [
        'omni_purchase', 'purchase', 'offsite_conversion.fb_pixel_purchase', 'app_custom_event.fb_mobile_purchase',
        'omni_complete_registration', 'complete_registration', 'lead', 'onsite_conversion.lead_grouped',
        'omni_app_install', 'mobile_app_install', 'app_install',
        'landing_page_view', 'link_click',
    ];

    /**
     * @param  iterable<array{actions: array<string, float>}>  $rows
     */
    protected function conversionAction(iterable $rows, ?string $requested): ?string
    {
        if (filled($requested)) {
            return $requested;
        }

        $available = $this->actionTotals($rows);

        foreach (self::CONVERSION_PRIORITY as $action) {
            if (($available[$action] ?? 0) > 0) {
                return $action;
            }
        }

        return null;
    }

    /**
     * Totals of every action type, largest first.
     *
     * @param  iterable<array{actions: array<string, float>}>  $rows
     * @return array<string, float>
     */
    protected function actionTotals(iterable $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            foreach ($row['actions'] as $type => $value) {
                $totals[$type] = ($totals[$type] ?? 0) + $value;
            }
        }

        arsort($totals);

        return array_map(fn (float $value): float => round($value, 2), $totals);
    }

    /**
     * Metrics with the chosen conversion; clicks are link clicks.
     *
     * @param  array{cost: float, impressions: int, reach: int, frequency: ?float, clicks: int, actions: array<string, float>, action_values: array<string, float>}  $row
     * @return array{cost: float, clicks: int, impressions: int, conversions: float, conversion_value: float, reach: int, frequency: ?float, ctr: ?float, cpc: ?float, cpm: ?float, cpa: ?float, roas: ?float}
     */
    protected function metaMetrics(array $row, ?string $conversionAction): array
    {
        $metrics = [
            'cost' => $row['cost'],
            'clicks' => $row['clicks'],
            'impressions' => $row['impressions'],
            'conversions' => round($conversionAction ? ($row['actions'][$conversionAction] ?? 0) : 0, 2),
            'conversion_value' => round($conversionAction ? ($row['action_values'][$conversionAction] ?? 0) : 0, 2),
        ];

        return $metrics + [
            'reach' => $row['reach'],
            'frequency' => $row['frequency'],
            'cpm' => $row['impressions'] > 0 ? round($row['cost'] / $row['impressions'] * 1000, 2) : null,
        ] + $this->derived($metrics);
    }

    /**
     * Totals over metaMetrics() rows; reach cannot be summed, so it is left out.
     *
     * @param  iterable<array{cost: float, clicks: int, impressions: int, conversions: float, conversion_value: float}>  $rows
     * @return array<string, float|int|null>
     */
    protected function metaTotals(iterable $rows): array
    {
        $totals = $this->totals($rows);
        $totals['cpm'] = $totals['impressions'] > 0 ? round($totals['cost'] / $totals['impressions'] * 1000, 2) : null;

        return $totals;
    }

    /**
     * @return array<string, JsonSchema>
     */
    protected function metaCommonSchema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()
                ->description('Meta ad account ID (with or without act_). Defaults to the account chosen in the settings; see list-meta-ad-accounts.'),
            'from' => $schema->string()
                ->description('Start date (YYYY-MM-DD). Defaults to 30 days before "to".'),
            'to' => $schema->string()
                ->description('End date (YYYY-MM-DD), included. Defaults to yesterday.'),
            'conversion_action' => $schema->string()
                ->description('Meta action type counted as conversion, e.g. omni_purchase, lead, mobile_app_install. Defaults to the first one with results among purchase, registration, lead, app install, landing page view, link click; available_actions lists what the account has.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function metaCommonRules(): array
    {
        return [
            'account_id' => 'nullable|string|max:30',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'conversion_action' => 'nullable|string|max:100',
        ];
    }
}

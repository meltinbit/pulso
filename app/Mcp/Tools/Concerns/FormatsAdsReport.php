<?php

namespace App\Mcp\Tools\Concerns;

use Illuminate\Support\Carbon;

/**
 * Derived Google Ads metrics and the default date range shared by the Ads
 * tools. Ratios are computed from summed totals, never averaged.
 */
trait FormatsAdsReport
{
    /**
     * Default range: the last 30 complete days (today is still partial).
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function adsRange(array $validated, int $defaultDays = 30): array
    {
        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : Carbon::yesterday();
        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : $to->copy()->subDays($defaultDays - 1);

        return [$from->startOfDay(), $to->startOfDay()];
    }

    /**
     * @param  array{cost: float, clicks: int, impressions: int, conversions: float, conversion_value: float}  $row
     * @return array{ctr: ?float, cpc: ?float, cpa: ?float, roas: ?float}
     */
    protected function derived(array $row): array
    {
        return [
            'ctr' => $row['impressions'] > 0 ? round($row['clicks'] / $row['impressions'] * 100, 2) : null,
            'cpc' => $row['clicks'] > 0 ? round($row['cost'] / $row['clicks'], 2) : null,
            'cpa' => $row['conversions'] > 0 ? round($row['cost'] / $row['conversions'], 2) : null,
            'roas' => $row['cost'] > 0 && $row['conversion_value'] > 0 ? round($row['conversion_value'] / $row['cost'], 2) : null,
        ];
    }

    /**
     * @param  iterable<array{cost: float, clicks: int, impressions: int, conversions: float, conversion_value: float}>  $rows
     * @return array{cost: float, clicks: int, impressions: int, conversions: float, conversion_value: float, ctr: ?float, cpc: ?float, cpa: ?float, roas: ?float}
     */
    protected function totals(iterable $rows): array
    {
        $totals = ['cost' => 0.0, 'clicks' => 0, 'impressions' => 0, 'conversions' => 0.0, 'conversion_value' => 0.0];

        foreach ($rows as $row) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += $row[$key];
            }
        }

        $totals['cost'] = round($totals['cost'], 2);
        $totals['conversions'] = round($totals['conversions'], 2);
        $totals['conversion_value'] = round($totals['conversion_value'], 2);

        return $totals + $this->derived($totals);
    }

    protected function percentChange(float|int $current, float|int $previous): ?float
    {
        return $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null;
    }
}

<?php

namespace App\Services;

use App\Models\GaProperty;
use App\Models\PropertyAdsenseMetric;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * Aggregates the stored per-day AdSense rows of a property over a date range.
 * Ratios (RPM, CTR, CPC, coverage) are always derived from summed totals, so
 * they are correctly weighted over the range.
 */
class AdSenseReportService
{
    /** Metrics compared against the previous period. */
    private const COMPARED_METRICS = ['earnings', 'page_views', 'impressions', 'clicks', 'page_rpm', 'page_ctr', 'cpc', 'coverage'];

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     currency: string,
     *     totals: array<string, int|float>,
     *     previous: array{from: string, to: string, totals: array<string, int|float>},
     *     changes: array<string, float|null>,
     *     daily: array<int, array<string, int|float|string>>,
     *     breakdowns: array<string, array<int, array<string, int|float|string>>>,
     *     last_synced_at: string|null,
     * }
     */
    public function summarize(GaProperty $property, Carbon $from, Carbon $to, int $breakdownLimit = 20): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        $days = (int) $from->diffInDays($to) + 1;
        $previousTo = $from->copy()->subDay();
        $previousFrom = $previousTo->copy()->subDays($days - 1);

        $totals = $this->totals($property, $from, $to);
        $previousTotals = $this->totals($property, $previousFrom, $previousTo);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currency' => $property->currency ?: 'EUR',
            'totals' => $totals,
            'previous' => [
                'from' => $previousFrom->toDateString(),
                'to' => $previousTo->toDateString(),
                'totals' => $previousTotals,
            ],
            'changes' => collect(self::COMPARED_METRICS)
                ->mapWithKeys(fn (string $metric): array => [$metric => $this->percentChange($totals[$metric], $previousTotals[$metric])])
                ->all(),
            'daily' => $this->daily($property, $from, $to),
            'breakdowns' => collect(PropertyAdsenseMetric::DIMENSIONS)
                ->except(PropertyAdsenseMetric::DIMENSION_TOTAL)
                ->map(fn ($reportDimension, string $dimension): array => $this->breakdown($property, $dimension, $from, $to, $breakdownLimit))
                ->all(),
            'last_synced_at' => $property->adsenseMetrics()->max('updated_at'),
        ];
    }

    public function hasData(GaProperty $property): bool
    {
        return $property->adsenseMetrics()->exists();
    }

    /**
     * @return array<string, int|float>
     */
    private function totals(GaProperty $property, Carbon $from, Carbon $to): array
    {
        $row = $this->baseQuery($property, PropertyAdsenseMetric::DIMENSION_TOTAL, $from, $to)
            ->selectRaw($this->sumColumns())
            ->first();

        return $this->withRatios((array) $row);
    }

    /**
     * @return array<int, array<string, int|float|string>>
     */
    private function daily(GaProperty $property, Carbon $from, Carbon $to): array
    {
        return $this->baseQuery($property, PropertyAdsenseMetric::DIMENSION_TOTAL, $from, $to)
            ->selectRaw('date, '.$this->sumColumns())
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn (object $row): array => [
                'date' => substr((string) $row->date, 0, 10),
                ...$this->withRatios((array) $row),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, int|float|string>>
     */
    private function breakdown(GaProperty $property, string $dimension, Carbon $from, Carbon $to, int $limit): array
    {
        return $this->baseQuery($property, $dimension, $from, $to)
            ->selectRaw('dimension_value, '.$this->sumColumns())
            ->groupBy('dimension_value')
            ->orderByRaw('SUM(earnings) DESC, SUM(page_views) DESC')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'name' => $row->dimension_value === '' ? '(non specificato)' : $row->dimension_value,
                ...$this->withRatios((array) $row),
            ])
            ->all();
    }

    private function baseQuery(GaProperty $property, string $dimension, Carbon $from, Carbon $to): Builder
    {
        return $property->adsenseMetrics()
            ->toBase()
            ->where('dimension', $dimension)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
    }

    private function sumColumns(): string
    {
        return 'COALESCE(SUM(earnings), 0) as earnings, COALESCE(SUM(page_views), 0) as page_views, '
            .'COALESCE(SUM(impressions), 0) as impressions, COALESCE(SUM(clicks), 0) as clicks, '
            .'COALESCE(SUM(ad_requests), 0) as ad_requests, COALESCE(SUM(matched_ad_requests), 0) as matched_ad_requests';
    }

    /**
     * @param  array<string, mixed>  $sums
     * @return array<string, int|float>
     */
    private function withRatios(array $sums): array
    {
        $earnings = round((float) ($sums['earnings'] ?? 0), 2);
        $pageViews = (int) ($sums['page_views'] ?? 0);
        $impressions = (int) ($sums['impressions'] ?? 0);
        $clicks = (int) ($sums['clicks'] ?? 0);
        $adRequests = (int) ($sums['ad_requests'] ?? 0);
        $matched = (int) ($sums['matched_ad_requests'] ?? 0);

        return [
            'earnings' => $earnings,
            'page_views' => $pageViews,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ad_requests' => $adRequests,
            'matched_ad_requests' => $matched,
            'page_rpm' => $pageViews > 0 ? round($earnings / $pageViews * 1000, 2) : 0.0,
            'impression_rpm' => $impressions > 0 ? round($earnings / $impressions * 1000, 2) : 0.0,
            'page_ctr' => $pageViews > 0 ? round($clicks / $pageViews * 100, 2) : 0.0,
            'cpc' => $clicks > 0 ? round($earnings / $clicks, 2) : 0.0,
            'coverage' => $adRequests > 0 ? round($matched / $adRequests * 100, 1) : 0.0,
        ];
    }

    private function percentChange(int|float $current, int|float $previous): ?float
    {
        if ((float) $previous === 0.0) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }
}

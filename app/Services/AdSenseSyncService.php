<?php

namespace App\Services;

use App\Exceptions\AdSenseApiException;
use App\Models\GaProperty;
use App\Models\PropertyAdsenseMetric;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Keeps the per-day AdSense metrics of a property in sync with Google.
 *
 * Every day is stored as a total row plus breakdown rows (country, platform,
 * ad unit), so any date range can be summed. Recent days are re-downloaded on
 * every run because estimated earnings keep being adjusted for a few days.
 */
class AdSenseSyncService
{
    /** Days re-downloaded on every run, today included. */
    public const RECENT_DAYS = 7;

    /** Default history downloaded by a backfill. */
    public const BACKFILL_MONTHS = 13;

    /** Days requested per API call. */
    public const CHUNK_DAYS = 31;

    /** Rows written per INSERT statement. */
    private const INSERT_CHUNK = 500;

    public function __construct(
        private AdSenseService $adSense,
    ) {}

    /**
     * Re-download the most recent days, overwriting whatever is stored for them.
     *
     * @throws AdSenseApiException
     */
    public function syncRecent(GaProperty $property, int $days = self::RECENT_DAYS): int
    {
        $to = $this->latestAvailableDate($property);

        return $this->syncRange($property, $to->copy()->subDays(max($days, 1) - 1), $to);
    }

    /**
     * Download a long history window, e.g. right after connecting AdSense.
     *
     * @throws AdSenseApiException
     */
    public function backfill(GaProperty $property, int $months = self::BACKFILL_MONTHS): int
    {
        $to = $this->latestAvailableDate($property);

        return $this->syncRange($property, $to->copy()->subMonthsNoOverflow($months)->addDay(), $to);
    }

    /**
     * Sync an arbitrary date range, one API window at a time.
     *
     * @return int the number of day total rows stored
     *
     * @throws AdSenseApiException
     */
    public function syncRange(GaProperty $property, Carbon $from, Carbon $to, int $chunkDays = self::CHUNK_DAYS): int
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        $stored = 0;

        for ($start = $from->copy(); $start->lte($to); $start->addDays($chunkDays)) {
            $end = $start->copy()->addDays($chunkDays - 1)->min($to);

            $rowsByDimension = collect(PropertyAdsenseMetric::DIMENSIONS)
                ->map(fn (?string $reportDimension): array => $this->adSense->fetchDailyRows(
                    $property,
                    $start->toDateString(),
                    $end->toDateString(),
                    $reportDimension,
                ));

            $stored += $this->storeRows($property, $start, $end, $rowsByDimension);
        }

        return $stored;
    }

    /**
     * Replace every stored row of each day in the window with the fetched ones,
     * per day inside a transaction so a re-run is idempotent.
     *
     * @param  Collection<string, array<int, array<string, mixed>>>  $rowsByDimension
     */
    private function storeRows(GaProperty $property, Carbon $from, Carbon $to, Collection $rowsByDimension): int
    {
        $rows = $rowsByDimension
            ->flatMap(fn (array $rows, string $dimension): array => array_map(
                fn (array $row): array => [...$row, 'dimension' => $dimension],
                $rows,
            ))
            ->groupBy('date');

        $stored = 0;

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $day = $date->toDateString();
            /** @var Collection<int, array<string, mixed>> $dayRows */
            $dayRows = $rows->get($day, collect());

            DB::transaction(function () use ($property, $day, $dayRows) {
                $property->adsenseMetrics()->where('date', $day)->delete();

                $now = now();

                foreach ($dayRows->chunk(self::INSERT_CHUNK) as $batch) {
                    PropertyAdsenseMetric::insert($batch->map(fn (array $row): array => [
                        'ga_property_id' => $property->id,
                        'date' => $day,
                        'dimension' => $row['dimension'],
                        'dimension_value' => Str::limit($row['value'], 191, ''),
                        'earnings' => $row['earnings'],
                        'page_views' => $row['page_views'],
                        'impressions' => $row['impressions'],
                        'clicks' => $row['clicks'],
                        'ad_requests' => $row['ad_requests'],
                        'matched_ad_requests' => $row['matched_ad_requests'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->values()->all());
                }
            });

            $stored += $dayRows->where('dimension', PropertyAdsenseMetric::DIMENSION_TOTAL)->count();
        }

        return $stored;
    }

    /**
     * AdSense reports today's partial earnings, so today (in the property's
     * timezone) is the newest day worth asking for.
     */
    private function latestAvailableDate(GaProperty $property): Carbon
    {
        return Carbon::today($property->timezone ?: 'UTC');
    }
}

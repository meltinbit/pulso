<?php

namespace App\Services;

use App\Exceptions\SearchConsoleApiException;
use App\Models\GaProperty;
use App\Models\PropertySearchQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Keeps the property-level Search Console rows in sync with Google.
 *
 * Rows are stored per day so that any date range can be summed, and recent days
 * are re-downloaded on every run because Search Console keeps consolidating a
 * day's data for a few days after it first appears.
 */
class SearchConsoleSyncService
{
    /** Days re-downloaded on every run, because Search Console consolidates late. */
    public const RECENT_DAYS = 4;

    /** How far back Google keeps Search Analytics data. */
    public const RETENTION_MONTHS = 16;

    /** Days requested per API call; each call is paginated internally. */
    public const CHUNK_DAYS = 7;

    /** Rows written per INSERT statement. */
    private const INSERT_CHUNK = 500;

    public function __construct(
        private SearchConsoleService $searchConsole,
    ) {}

    /**
     * Re-download the most recent days, overwriting whatever is stored for them.
     *
     * @throws SearchConsoleApiException
     */
    public function syncRecent(GaProperty $property, int $days = self::RECENT_DAYS): int
    {
        $to = $this->latestAvailableDate();

        return $this->syncRange($property, $to->copy()->subDays(max($days, 1) - 1), $to);
    }

    /**
     * Download the full window Google still retains.
     *
     * @throws SearchConsoleApiException
     */
    public function backfill(GaProperty $property, int $months = self::RETENTION_MONTHS): int
    {
        $to = $this->latestAvailableDate();
        $from = $to->copy()->subMonthsNoOverflow($months)->addDay();

        return $this->syncRange($property, $from, $to);
    }

    /**
     * Sync an arbitrary date range, one API window at a time.
     *
     * @return int the number of rows stored
     *
     * @throws SearchConsoleApiException
     */
    public function syncRange(GaProperty $property, Carbon $from, Carbon $to, int $chunkDays = self::CHUNK_DAYS): int
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        $stored = 0;

        for ($start = $from->copy(); $start->lte($to); $start->addDays($chunkDays)) {
            $end = $start->copy()->addDays($chunkDays - 1)->min($to);

            $rows = $this->searchConsole->fetchDailyRows($property, $start->toDateString(), $end->toDateString());

            $stored += $this->storeRows($property, $start, $end, $rows);
        }

        return $stored;
    }

    /**
     * Replace the stored rows of every day in the window with the fetched ones.
     * Done per day inside a transaction so a range query never sees a half
     * written day, and so a re-run is idempotent.
     *
     * @param  array<int, array{date: string, query: string, page: string|null, clicks: int, impressions: int, ctr: float, position: float}>  $rows
     */
    private function storeRows(GaProperty $property, Carbon $from, Carbon $to, array $rows): int
    {
        $rowsByDate = collect($rows)->groupBy('date');
        $stored = 0;

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $day = $date->toDateString();
            /** @var Collection<int, array<string, mixed>> $dayRows */
            $dayRows = $rowsByDate->get($day, collect());

            if ($dayRows->isEmpty() && ! $this->hasStoredRows($property, $day)) {
                continue;
            }

            DB::transaction(function () use ($property, $day, $dayRows) {
                PropertySearchQuery::where('ga_property_id', $property->id)
                    ->where('date', $day)
                    ->delete();

                $now = now();

                foreach ($dayRows->chunk(self::INSERT_CHUNK) as $batch) {
                    PropertySearchQuery::insert($batch->map(fn (array $row): array => [
                        'ga_property_id' => $property->id,
                        'date' => $day,
                        'query' => Str::limit($row['query'], 255, ''),
                        'page' => $row['page'] === null ? null : Str::limit($row['page'], 1024, ''),
                        'clicks' => $row['clicks'],
                        'impressions' => $row['impressions'],
                        'ctr' => $row['ctr'],
                        'position' => $row['position'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all());
                }
            });

            $stored += $dayRows->count();
        }

        return $stored;
    }

    private function hasStoredRows(GaProperty $property, string $date): bool
    {
        return PropertySearchQuery::where('ga_property_id', $property->id)
            ->where('date', $date)
            ->exists();
    }

    /**
     * Search Console never has data for today, so yesterday is the newest day
     * worth asking for.
     */
    private function latestAvailableDate(): Carbon
    {
        return Carbon::today('UTC')->subDay();
    }
}

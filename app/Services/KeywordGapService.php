<?php

namespace App\Services;

use App\Models\GaProperty;
use App\Models\PropertySearchQuery;
use Illuminate\Support\Str;

/**
 * Cross-references keyword research rows with the Search Console queries a
 * property already ranks for: what people search vs where the site shows up.
 */
class KeywordGapService
{
    /** Search Console window used for the comparison. */
    public const DAYS = 90;

    /**
     * Annotate keyword rows with the property's Search Console performance.
     * `gsc` is null when the site never appeared for that query in the window.
     *
     * @template TRow of array{keyword: string}
     *
     * @param  array<int, TRow>  $rows
     * @return array<int, TRow&array{gsc: array{position: float, impressions: int, clicks: int}|null}>
     */
    public function annotate(GaProperty $property, array $rows): array
    {
        $performance = $this->searchConsolePerformance($property);

        return array_map(function (array $row) use ($performance): array {
            $row['gsc'] = $performance[self::normalize($row['keyword'])] ?? null;

            return $row;
        }, $rows);
    }

    /**
     * Counts of present vs missing keywords, plus the search volume the site is missing.
     *
     * @param  array<int, array{volume: int|null, gsc: array<string, mixed>|null}>  $annotated
     * @return array{total: int, present: int, missing: int, missing_volume: int}
     */
    public function summarize(array $annotated): array
    {
        $missing = array_filter($annotated, fn (array $row): bool => $row['gsc'] === null);

        return [
            'total' => count($annotated),
            'present' => count($annotated) - count($missing),
            'missing' => count($missing),
            'missing_volume' => (int) array_sum(array_column($missing, 'volume')),
        ];
    }

    /**
     * Lowercase, strip accents and collapse whitespace, so "Perché  Ferrara"
     * and "perche ferrara" match.
     */
    public static function normalize(string $keyword): string
    {
        return Str::squish(Str::ascii(mb_strtolower($keyword)));
    }

    /**
     * Search Console totals per normalized query for the last {@see DAYS} days,
     * with position weighted by impressions.
     *
     * @return array<string, array{position: float, impressions: int, clicks: int}>
     */
    private function searchConsolePerformance(GaProperty $property): array
    {
        $rows = PropertySearchQuery::where('ga_property_id', $property->id)
            ->where('date', '>=', now()->subDays(self::DAYS)->toDateString())
            ->selectRaw('`query`, SUM(clicks) as total_clicks, SUM(impressions) as total_impressions, SUM(position * impressions) as weighted_position')
            ->groupBy('query')
            ->toBase()
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $key = self::normalize((string) $row->query);
            $totals[$key] ??= ['clicks' => 0, 'impressions' => 0, 'weighted' => 0.0];
            $totals[$key]['clicks'] += (int) $row->total_clicks;
            $totals[$key]['impressions'] += (int) $row->total_impressions;
            $totals[$key]['weighted'] += (float) $row->weighted_position;
        }

        return array_map(fn (array $total): array => [
            'position' => $total['impressions'] > 0 ? round($total['weighted'] / $total['impressions'], 1) : 0.0,
            'impressions' => $total['impressions'],
            'clicks' => $total['clicks'],
        ], $totals);
    }
}

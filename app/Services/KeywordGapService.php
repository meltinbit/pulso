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

    /** Below this volume identical metrics are too common to mean "same keyword group". */
    public const VARIANT_MIN_VOLUME = 50;

    /**
     * Annotate keyword rows with the property's Search Console performance.
     * `gsc` is null when the site never appeared for that query in the window.
     *
     * Google Ads groups close variants ("calcolo imu", "calcolatori imu") and
     * reports the same volume and monthly series for each of them. When the
     * site ranks for one keyword of such a group, the other members get
     * `variant_of` set to that keyword instead of counting as a gap.
     *
     * @template TRow of array{keyword: string}
     *
     * @param  array<int, TRow>  $rows
     * @return array<int, TRow&array{gsc: array{position: float, impressions: int, clicks: int}|null, variant_of: string|null}>
     */
    public function annotate(GaProperty $property, array $rows): array
    {
        $performance = $this->searchConsolePerformance($property);

        $rows = array_map(function (array $row) use ($performance): array {
            $row['gsc'] = $performance[self::normalize($row['keyword'])] ?? null;
            $row['variant_of'] = null;

            return $row;
        }, $rows);

        foreach ($this->variantGroups($rows) as $indexes) {
            $present = collect($indexes)
                ->filter(fn (int $i): bool => $rows[$i]['gsc'] !== null)
                ->sortByDesc(fn (int $i): int => $rows[$i]['gsc']['impressions'])
                ->first();

            if ($present === null) {
                continue;
            }

            foreach ($indexes as $i) {
                if ($rows[$i]['gsc'] === null) {
                    $rows[$i]['variant_of'] = $rows[$present]['keyword'];
                }
            }
        }

        return $rows;
    }

    /**
     * Counts of present vs missing keywords, plus the search volume the site
     * is missing. Variants of a present keyword count as present, and a group
     * of variants contributes its (shared) volume only once.
     *
     * @param  array<int, array{volume: int|null, gsc: array<string, mixed>|null, variant_of?: string|null}>  $annotated
     * @return array{total: int, present: int, variants: int, missing: int, missing_volume: int}
     */
    public function summarize(array $annotated): array
    {
        $missing = array_filter($annotated, fn (array $row): bool => self::isGap($row));
        $variants = array_filter($annotated, fn (array $row): bool => ($row['variant_of'] ?? null) !== null);

        $counted = [];
        $missingVolume = 0;

        foreach ($missing as $index => $row) {
            $key = $this->variantSignature($row) ?? "row:{$index}";

            if (! isset($counted[$key])) {
                $counted[$key] = true;
                $missingVolume += (int) ($row['volume'] ?? 0);
            }
        }

        return [
            'total' => count($annotated),
            'present' => count($annotated) - count($missing),
            'variants' => count($variants),
            'missing' => count($missing),
            'missing_volume' => $missingVolume,
        ];
    }

    /**
     * A keyword is a gap when the site does not appear for it nor for a variant of it.
     *
     * @param  array{gsc: array<string, mixed>|null, variant_of?: string|null}  $row
     */
    public static function isGap(array $row): bool
    {
        return $row['gsc'] === null && ($row['variant_of'] ?? null) === null;
    }

    /**
     * Row indexes grouped by identical Google Ads metrics (likely close variants).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<int, int>>
     */
    private function variantGroups(array $rows): array
    {
        $groups = [];

        foreach ($rows as $index => $row) {
            if ($signature = $this->variantSignature($row)) {
                $groups[$signature][] = $index;
            }
        }

        return array_filter($groups, fn (array $indexes): bool => count($indexes) > 1);
    }

    /**
     * Fingerprint of the metrics Google repeats across close variants, or
     * null when the row has too little volume or history to be meaningful.
     *
     * @param  array<string, mixed>  $row
     */
    private function variantSignature(array $row): ?string
    {
        if (($row['volume'] ?? 0) < self::VARIANT_MIN_VOLUME || empty($row['monthly'])) {
            return null;
        }

        return md5(json_encode([
            $row['volume'],
            $row['monthly'],
            $row['competition_index'] ?? null,
            $row['cpc_low'] ?? null,
            $row['cpc_high'] ?? null,
        ]));
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

<?php

namespace App\Mcp\Tools\Concerns;

use Laravel\Mcp\Response;

/**
 * Shared helpers for the keyword research tools: lenient list parsing and a
 * compact columnar JSON output that keeps token usage low.
 */
trait FormatsKeywordResearch
{
    /**
     * Accept a real array, a JSON encoded array or a comma separated string.
     *
     * @return array<int, string>
     */
    protected function listParam(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(fn (mixed $item): string => trim((string) $item), $value), fn (string $item): bool => $item !== ''));
    }

    /**
     * Row for a keyword: [kw, vol, trend%, comp, comp_idx, cpc_lo, cpc_hi].
     *
     * @param  array{keyword: string, volume: int|null, trend: float|null, competition: string|null, competition_index: int|null, cpc_low: float|null, cpc_high: float|null}  $row
     * @return array<int, mixed>
     */
    protected function keywordRow(array $row): array
    {
        return [
            $row['keyword'],
            $row['volume'],
            $row['trend'] === null ? null : (int) round($row['trend']),
            $row['competition'] === null ? null : $row['competition'][0],
            $row['competition_index'],
            $row['cpc_low'],
            $row['cpc_high'],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function keywordColumns(): array
    {
        return ['kw', 'vol', 'trend%', 'comp', 'comp_idx', 'cpc_lo', 'cpc_hi'];
    }

    /**
     * Search Console columns appended to a keyword row: [gsc_pos, gsc_impr, gsc_clicks].
     *
     * @param  array{position: float, impressions: int, clicks: int}|null  $gsc
     * @return array<int, float|int|null>
     */
    protected function gscCells(?array $gsc): array
    {
        return [$gsc['position'] ?? null, $gsc['impressions'] ?? null, $gsc['clicks'] ?? null];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function compactResponse(array $result): Response
    {
        return Response::text(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    protected function legend(): string
    {
        return 'vol=avg monthly searches (approx, last 12 months); trend%=last 3 months vs first 3; comp=L/M/H; comp_idx=0-100; cpc_lo/cpc_hi=top of page bid range in account currency';
    }
}

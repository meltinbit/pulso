<?php

namespace App\Services;

use App\Models\GaProperty;
use App\Models\PropertyBingSearchStat;
use Illuminate\Support\Carbon;

/**
 * Bing as a GEO signal: ChatGPT search, Copilot and other AI engines lean
 * on the Bing index, so a prompt the site is not cited for often matches a
 * Bing query where the site is weak or absent. Prompts are full questions
 * and Bing queries are short, so they are matched on shared meaningful words.
 */
class BingGeoSignal
{
    /** Weeks of Bing query data used for the match. */
    public const WEEKS = 12;

    private const STOPWORDS = [
        'il', 'lo', 'la', 'i', 'gli', 'le', 'un', 'uno', 'una', 'di', 'del', 'della', 'dei', 'degli', 'delle', 'da', 'dal', 'dalla',
        'in', 'nel', 'nella', 'con', 'su', 'per', 'tra', 'fra', 'che', 'chi', 'come', 'cosa', 'qual', 'quale', 'quali', 'quanto',
        'quanta', 'quanti', 'quante', 'dove', 'quando', 'perche', 'mi', 'ti', 'si', 'ci', 'vi', 'non', 'piu', 'meglio', 'miglior',
        'migliore', 'migliori', 'mio', 'mia', 'miei', 'mie', 'suo', 'sua', 'sono', 'essere', 'devo', 'posso', 'puoi', 'fare', 'and',
        'the', 'for', 'with', 'how', 'what', 'which', 'best', 'from', 'are', 'can', 'does', 'per', 'o', 'e', 'ed', 'al', 'alla', 'ai',
        'agli', 'alle', 'nei', 'nelle', 'sul', 'sulla', 'consigli', 'consigliami',
    ];

    /**
     * The Bing query of the last weeks that best matches each prompt.
     *
     * @param  array<int, string>  $prompts  prompt text keyed by prompt ID
     * @return array<int, array{query: string, impressions: int, clicks: int, position: ?float}>
     */
    public function matchPrompts(GaProperty $property, array $prompts): array
    {
        if ($prompts === [] || $property->bing_site_url === null) {
            return [];
        }

        $queries = PropertyBingSearchStat::query()
            ->where('ga_property_id', $property->id)
            ->where('dimension', PropertyBingSearchStat::DIMENSION_QUERY)
            ->whereDate('week_start', '>=', Carbon::today()->subWeeks(self::WEEKS)->toDateString())
            ->selectRaw('value, SUM(clicks) as clicks, SUM(impressions) as impressions, SUM(avg_impression_position * impressions) * 1.0 / NULLIF(SUM(impressions), 0) as position')
            ->groupBy('value')
            ->get()
            ->map(fn ($row): array => [
                'query' => (string) $row->value,
                'tokens' => self::tokens((string) $row->value),
                'impressions' => (int) $row->impressions,
                'clicks' => (int) $row->clicks,
                'position' => $row->position === null ? null : round((float) $row->position, 1),
            ])
            ->filter(fn (array $query): bool => $query['tokens'] !== []);

        $matches = [];

        foreach ($prompts as $id => $prompt) {
            $promptTokens = self::tokens($prompt);
            $best = null;
            $bestScore = 0.0;

            foreach ($queries as $query) {
                $shared = count(array_intersect($query['tokens'], $promptTokens));

                if ($shared < min(2, count($query['tokens']))) {
                    continue;
                }

                $score = $shared / count($query['tokens']) + $shared / max(count($promptTokens), 1);

                if ($score > $bestScore || ($score === $bestScore && $best !== null && $query['impressions'] > $best['impressions'])) {
                    $best = $query;
                    $bestScore = $score;
                }
            }

            if ($best !== null && $bestScore >= 1.0) {
                $matches[$id] = array_intersect_key($best, array_flip(['query', 'impressions', 'clicks', 'position']));
            }
        }

        return $matches;
    }

    /**
     * Bing clicks and impressions of the last 28 days against the 28 before.
     *
     * @return array{to: string, clicks: int, impressions: int, previous_clicks: int, previous_impressions: int, impressions_change_pct: ?float}|null
     */
    public function trend(GaProperty $property): ?array
    {
        if ($property->bing_site_url === null) {
            return null;
        }

        $end = $property->bingTraffic()->max('date');

        if ($end === null) {
            return null;
        }

        $end = Carbon::parse($end);
        $sum = fn (Carbon $from, Carbon $to): array => [
            (int) $property->bingTraffic()->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->sum('clicks'),
            (int) $property->bingTraffic()->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())->sum('impressions'),
        ];

        [$clicks, $impressions] = $sum($end->copy()->subDays(27), $end);
        [$previousClicks, $previousImpressions] = $sum($end->copy()->subDays(55), $end->copy()->subDays(28));

        return [
            'to' => $end->toDateString(),
            'clicks' => $clicks,
            'impressions' => $impressions,
            'previous_clicks' => $previousClicks,
            'previous_impressions' => $previousImpressions,
            'impressions_change_pct' => $previousImpressions > 0 ? round(($impressions - $previousImpressions) / $previousImpressions * 100, 1) : null,
        ];
    }

    /**
     * Meaningful words of a text: lowercase, no accents, no stopwords, at
     * least three characters, without a trailing plural/gender vowel so
     * "calcolatore" and "calcolatori" match.
     *
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', KeywordGapService::normalize($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_map(
            fn (string $word): string => strlen($word) > 4 ? preg_replace('/[aeio]$/', '', $word) : $word,
            array_filter($words, fn (string $word): bool => strlen($word) >= 3 && ! in_array($word, self::STOPWORDS, true)),
        )));
    }
}

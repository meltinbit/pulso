<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Real user experience from the Chrome UX Report (CrUX API and CrUX
 * History API, 28 day windows at the 75th percentile) and lab audits from
 * PageSpeed Insights (Lighthouse). Both use a Google Cloud API key saved in
 * Impostazioni → Google.
 */
class PageExperienceService
{
    public const API_KEY_SETTING = 'google_api_key';

    private const CRUX = 'https://chromeuxreport.googleapis.com/v1/records:';

    private const PSI = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    /**
     * Metric thresholds at p75 (good up to the first value, poor above the
     * second), as defined by web.dev for Core Web Vitals.
     *
     * @var array<string, array{0: float, 1: float}>
     */
    public const THRESHOLDS = [
        'largest_contentful_paint' => [2500, 4000],
        'interaction_to_next_paint' => [200, 500],
        'cumulative_layout_shift' => [0.1, 0.25],
        'first_contentful_paint' => [1800, 3000],
        'experimental_time_to_first_byte' => [800, 1800],
    ];

    public const CORE_WEB_VITALS = ['largest_contentful_paint', 'interaction_to_next_paint', 'cumulative_layout_shift'];

    public function __construct(private SettingService $settings) {}

    public function apiKey(User $user): ?string
    {
        return $this->settings->get($user->id, self::API_KEY_SETTING) ?: null;
    }

    /**
     * Current p75 of each metric, or null when CrUX has too little traffic
     * for that origin or URL.
     *
     * @param  array{origin?: string, url?: string}  $target
     * @return array{period: array{from: string, to: string}, metrics: array<string, float>}|null
     */
    public function record(string $apiKey, array $target, ?string $formFactor): ?array
    {
        $record = $this->crux($apiKey, 'queryRecord', $target + array_filter(['formFactor' => $formFactor, 'metrics' => array_keys(self::THRESHOLDS)]));

        if ($record === null) {
            return null;
        }

        return [
            'period' => $this->period($record['collectionPeriod'] ?? []),
            'metrics' => collect($record['metrics'] ?? [])
                ->map(fn (array $metric): mixed => $metric['percentiles']['p75'] ?? null)
                ->filter(fn ($value): bool => is_numeric($value))
                ->map(fn ($value): float => (float) $value)
                ->all(),
        ];
    }

    /**
     * Weekly p75 series of the Core Web Vitals, oldest first.
     *
     * @param  array{origin?: string, url?: string}  $target
     * @return array{periods: list<string>, metrics: array<string, list<float|null>>}|null
     */
    public function history(string $apiKey, array $target, ?string $formFactor, int $periods): ?array
    {
        $record = $this->crux($apiKey, 'queryHistoryRecord', $target + array_filter([
            'formFactor' => $formFactor,
            'metrics' => self::CORE_WEB_VITALS,
            'collectionPeriodCount' => $periods,
        ]));

        if ($record === null) {
            return null;
        }

        return [
            'periods' => collect($record['collectionPeriods'] ?? [])->map(fn (array $period): string => $this->period($period)['to'])->all(),
            'metrics' => collect($record['metrics'] ?? [])
                ->map(fn (array $metric): array => array_map(fn ($value): ?float => is_numeric($value) ? (float) $value : null, $metric['percentilesTimeseries']['p75s'] ?? []))
                ->all(),
        ];
    }

    /**
     * Lighthouse performance audit of one page: score, lab metrics and the
     * failing audits with the biggest estimated savings.
     *
     * @return array{score: ?int, metrics: array<string, ?string>, opportunities: list<array{id: string, title: string, value: ?string, savings: array<string, float>}>}
     */
    public function lighthouse(string $apiKey, string $url, string $strategy): array
    {
        $result = Cache::remember('psi:'.sha1($url.'|'.$strategy), 3600, function () use ($apiKey, $url, $strategy): array {
            $response = Http::timeout(90)->get(self::PSI, ['url' => $url, 'strategy' => $strategy, 'category' => 'performance', 'key' => $apiKey]);

            if ($response->failed()) {
                throw new RuntimeException('PageSpeed Insights: '.($response->json('error.message') ?: 'HTTP '.$response->status()));
            }

            return $response->json('lighthouseResult') ?? [];
        });

        $audits = $result['audits'] ?? [];
        $metricAudits = [
            'largest-contentful-paint' => 'lcp', 'first-contentful-paint' => 'fcp', 'total-blocking-time' => 'tbt',
            'cumulative-layout-shift' => 'cls', 'speed-index' => 'speed_index', 'server-response-time' => 'ttfb',
        ];
        $score = $result['categories']['performance']['score'] ?? null;

        return [
            'score' => is_numeric($score) ? (int) round($score * 100) : null,
            'metrics' => collect($metricAudits)->mapWithKeys(fn (string $name, string $id): array => [$name => $audits[$id]['displayValue'] ?? null])->all(),
            'opportunities' => collect($audits)
                ->reject(fn (array $audit, string $id): bool => isset($metricAudits[$id]))
                ->filter(fn (array $audit): bool => is_numeric($audit['score'] ?? null) && $audit['score'] < 0.9)
                ->map(function (array $audit, string $id): array {
                    $savings = collect($audit['metricSavings'] ?? [])->filter(fn ($value): bool => is_numeric($value) && $value > 0)->map(fn ($value): float => round((float) $value, 3))->all();

                    if ($savings === [] && is_numeric($audit['details']['overallSavingsMs'] ?? null) && $audit['details']['overallSavingsMs'] > 0) {
                        $savings = ['ms' => round((float) $audit['details']['overallSavingsMs'])];
                    }

                    return ['id' => $id, 'title' => $audit['title'] ?? $id, 'value' => $audit['displayValue'] ?? null, 'savings' => $savings];
                })
                ->filter(fn (array $audit): bool => $audit['savings'] !== [])
                ->sortByDesc(fn (array $audit): float => ($audit['savings']['LCP'] ?? 0) + ($audit['savings']['FCP'] ?? 0) + ($audit['savings']['TBT'] ?? 0) + ($audit['savings']['ms'] ?? 0) + ($audit['savings']['CLS'] ?? 0) * 10000)
                ->take(8)
                ->values()
                ->all(),
        ];
    }

    /**
     * good, needs_improvement or poor for a p75 value.
     */
    public static function rating(string $metric, float $value): ?string
    {
        if (! isset(self::THRESHOLDS[$metric])) {
            return null;
        }

        [$good, $poor] = self::THRESHOLDS[$metric];

        return $value <= $good ? 'good' : ($value <= $poor ? 'needs_improvement' : 'poor');
    }

    /**
     * One CrUX call, cached for 12 hours (the dataset is updated daily).
     * Returns null when CrUX has no data for the target (HTTP 404).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    private function crux(string $apiKey, string $method, array $body): ?array
    {
        $record = Cache::remember('crux:'.$method.':'.sha1((string) json_encode($body)), 43200, function () use ($apiKey, $method, $body): array {
            $response = Http::timeout(20)->post(self::CRUX.$method.'?key='.urlencode($apiKey), $body);

            if ($response->status() === 404) {
                return ['none' => true];
            }

            if ($response->failed()) {
                throw new RuntimeException('Chrome UX Report: '.($response->json('error.message') ?: 'HTTP '.$response->status()));
            }

            return $response->json('record') ?? ['none' => true];
        });

        return isset($record['none']) ? null : $record;
    }

    /**
     * @param  array{firstDate?: array{year: int, month: int, day: int}, lastDate?: array{year: int, month: int, day: int}}  $period
     * @return array{from: string, to: string}
     */
    private function period(array $period): array
    {
        $date = fn (?array $date): string => $date === null ? '' : sprintf('%04d-%02d-%02d', $date['year'], $date['month'], $date['day']);

        return ['from' => $date($period['firstDate'] ?? null), 'to' => $date($period['lastDate'] ?? null)];
    }
}

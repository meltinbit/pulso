<?php

namespace App\Services;

use App\Models\GaProperty;
use App\Models\PropertySnapshot;
use Illuminate\Support\Carbon;

class SnapshotAnalyzerService
{
    public function __construct(
        private GaClientService $gaClient,
        private SearchConsoleService $searchConsole,
    ) {}

    /**
     * Analyze a property for a given date and create/update its snapshot.
     */
    public function analyze(GaProperty $property, Carbon $date): PropertySnapshot
    {
        $snapshotDate = $date->copy()->startOfDay();
        $yesterday = $date->toDateString();
        $weekAgo = $date->copy()->subWeek()->toDateString();
        $thirtyDaysAgo = $date->copy()->subDays(30)->toDateString();
        $oneDayBefore = $date->copy()->subDay()->toDateString();

        $yesterdayData = $this->fetchDayMetrics($property, $yesterday, $yesterday);
        $weekAgoData = $this->fetchDayMetrics($property, $weekAgo, $weekAgo);
        $avg30dData = $this->fetchAverageMetrics($property, $thirtyDaysAgo, $oneDayBefore);
        $sourcesData = $this->fetchSources($property, $yesterday, $yesterday);
        $engagementData = $this->fetchEngagementMetrics($property, $yesterday, $yesterday);
        $pagesData = $this->fetchPages($property, $yesterday, $yesterday);

        $users = $this->extractMetric($yesterdayData, 0);
        $sessions = $this->extractMetric($yesterdayData, 1);
        $pageviews = $this->extractMetric($yesterdayData, 2);
        $bounceRate = $this->extractMetricFloat($yesterdayData, 3);
        $avgDuration = $this->extractMetric($yesterdayData, 4);

        $usersWeekAgo = $this->extractMetric($weekAgoData, 0);
        $sessionsWeekAgo = $this->extractMetric($weekAgoData, 1);
        $pageviewsWeekAgo = $this->extractMetric($weekAgoData, 2);
        $bounceWeekAgo = $this->extractMetricFloat($weekAgoData, 3);

        $usersAvg30d = $this->extractMetricFloat($avg30dData, 0);
        $sessionsAvg30d = $this->extractMetricFloat($avg30dData, 1);

        $usersDeltaWow = $this->computeDelta($users, $usersWeekAgo);
        $sessionsDeltaWow = $this->computeDelta($sessions, $sessionsWeekAgo);
        $pageviewsDeltaWow = $this->computeDelta($pageviews, $pageviewsWeekAgo);
        $bounceDeltaWow = $bounceWeekAgo > 0 ? $bounceRate - $bounceWeekAgo : null;
        $usersDelta30d = $usersAvg30d > 0 ? round((($users - $usersAvg30d) / $usersAvg30d) * 100, 2) : null;
        $sessionsDelta30d = $sessionsAvg30d > 0 ? round((($sessions - $sessionsAvg30d) / $sessionsAvg30d) * 100, 2) : null;

        $trend = $this->categorizeTrend($usersDeltaWow ?? 0.0);
        $trendScore = $this->computeTrendScore($usersDeltaWow ?? 0.0, $usersDelta30d ?? 0.0);
        $anomaly = $this->detectAnomaly($users, $usersAvg30d);

        $topSources = $this->parseSources($sourcesData);

        $engagedSessions = $this->extractMetric($engagementData, 0);
        $engagementRate = $this->extractMetricFloat($engagementData, 1);
        $pagesPerSession = $sessions > 0 ? round($pageviews / $sessions, 2) : 0;

        $topPages = $this->parsePages($pagesData);

        $snapshot = PropertySnapshot::updateOrCreate(
            ['ga_property_id' => $property->id, 'snapshot_date' => $snapshotDate],
            [
                'period' => 'daily',
                'users' => $users,
                'sessions' => $sessions,
                'pageviews' => $pageviews,
                'bounce_rate' => $bounceRate,
                'avg_session_duration' => $avgDuration,
                'top_sources' => $topSources,
                'users_delta_wow' => $usersDeltaWow,
                'sessions_delta_wow' => $sessionsDeltaWow,
                'pageviews_delta_wow' => $pageviewsDeltaWow,
                'bounce_delta_wow' => $bounceDeltaWow,
                'users_delta_30d' => $usersDelta30d,
                'sessions_delta_30d' => $sessionsDelta30d,
                'trend' => $trend,
                'trend_score' => $trendScore,
                'pages_per_session' => $pagesPerSession,
                'engaged_sessions' => $engagedSessions,
                'engagement_rate' => $engagementRate,
                'is_spike' => $anomaly['is_spike'],
                'is_drop' => $anomaly['is_drop'],
                'is_stall' => abs($usersDeltaWow ?? 0) < 5,
            ]
        );

        $snapshot->sources()->delete();
        foreach ($topSources as $source) {
            $snapshot->sources()->create([
                'source' => $source['source'],
                'medium' => $source['medium'],
                'sessions' => $source['sessions'],
                'users' => $source['users'],
            ]);
        }

        $snapshot->pages()->delete();
        foreach ($topPages as $page) {
            $snapshot->pages()->create($page);
        }

        $searchQueries = $this->searchConsole->fetchSearchQueries($property, $yesterday);
        $snapshot->searchQueries()->delete();
        foreach ($searchQueries as $query) {
            $snapshot->searchQueries()->create($query);
        }

        return $snapshot;
    }

    /**
     * Categorize trend based on WoW delta percentage.
     */
    public function categorizeTrend(float $wowDelta): string
    {
        return match (true) {
            $wowDelta > 50 => 'spike',
            $wowDelta > 10 => 'improved',
            $wowDelta >= -10 => 'stall',
            $wowDelta >= -30 => 'declined',
            default => 'drop',
        };
    }

    /**
     * Compute a composite trend score from -100 to +100.
     * Weighted: 60% WoW, 40% 30-day delta.
     */
    public function computeTrendScore(float $wowDelta, float $delta30d): float
    {
        $raw = ($wowDelta * 0.6) + ($delta30d * 0.4);

        return round(max(-100, min(100, $raw)), 2);
    }

    /**
     * Detect anomalies by comparing current users to 30-day average.
     *
     * @return array{is_spike: bool, is_drop: bool}
     */
    public function detectAnomaly(int $users, float $avg30d): array
    {
        if ($avg30d <= 0) {
            return ['is_spike' => false, 'is_drop' => false];
        }

        $delta = (($users - $avg30d) / $avg30d) * 100;

        return [
            'is_spike' => $delta > 50,
            'is_drop' => $delta < -30,
        ];
    }

    /**
     * Fetch core metrics for a date range from GA4.
     *
     * @return array<string, mixed>
     */
    private function fetchDayMetrics(GaProperty $property, string $startDate, string $endDate): array
    {
        return $this->gaClient->runReport($property, [
            'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
            'metrics' => [
                ['name' => 'activeUsers'],
                ['name' => 'sessions'],
                ['name' => 'screenPageViews'],
                ['name' => 'bounceRate'],
                ['name' => 'averageSessionDuration'],
            ],
        ]);
    }

    /**
     * Fetch average metrics over a longer period (for 30-day comparison).
     *
     * @return array<string, mixed>
     */
    private function fetchAverageMetrics(GaProperty $property, string $startDate, string $endDate): array
    {
        return $this->gaClient->runReport($property, [
            'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
            'metrics' => [
                ['name' => 'activeUsers'],
                ['name' => 'sessions'],
            ],
        ]);
    }

    /**
     * Fetch traffic sources for a date range.
     *
     * @return array<string, mixed>
     */
    private function fetchSources(GaProperty $property, string $startDate, string $endDate): array
    {
        return $this->gaClient->runReport($property, [
            'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
            'dimensions' => [
                ['name' => 'sessionSource'],
                ['name' => 'sessionMedium'],
            ],
            'metrics' => [
                ['name' => 'sessions'],
                ['name' => 'activeUsers'],
            ],
            'limit' => 10,
        ]);
    }

    /**
     * Extract an integer metric from GA4 response row 0.
     */
    private function extractMetric(array $response, int $index): int
    {
        return (int) data_get($response, "rows.0.metricValues.{$index}.value", 0);
    }

    /**
     * Extract a float metric from GA4 response row 0.
     */
    private function extractMetricFloat(array $response, int $index): float
    {
        return (float) data_get($response, "rows.0.metricValues.{$index}.value", 0);
    }

    /**
     * Compute percentage delta between current and previous values.
     */
    private function computeDelta(int $current, int $previous): ?float
    {
        if ($previous <= 0) {
            return $current > 0 ? 100.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }

    /**
     * Fetch engagement metrics for a date range from GA4.
     *
     * @return array<string, mixed>
     */
    private function fetchEngagementMetrics(GaProperty $property, string $startDate, string $endDate): array
    {
        return $this->gaClient->runReport($property, [
            'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
            'metrics' => [
                ['name' => 'engagedSessions'],
                ['name' => 'engagementRate'],
            ],
        ]);
    }

    /**
     * Fetch top pages with per-page metrics from GA4.
     *
     * @return array<string, mixed>
     */
    private function fetchPages(GaProperty $property, string $startDate, string $endDate): array
    {
        return $this->gaClient->runReport($property, [
            'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
            'dimensions' => [
                ['name' => 'pagePath'],
                ['name' => 'pageTitle'],
            ],
            'metrics' => [
                ['name' => 'screenPageViews'],
                ['name' => 'activeUsers'],
                ['name' => 'bounceRate'],
                ['name' => 'userEngagementDuration'],
                ['name' => 'engagementRate'],
            ],
            'orderBys' => [
                ['metric' => ['metricName' => 'screenPageViews'], 'desc' => true],
            ],
            'limit' => 20,
        ]);
    }

    /**
     * Parse GA4 sources response into a structured array.
     *
     * @return array<int, array{source: string, medium: string, sessions: int, users: int}>
     */
    private function parseSources(array $response): array
    {
        $rows = data_get($response, 'rows', []);
        $sources = [];

        foreach ($rows as $row) {
            $sources[] = [
                'source' => data_get($row, 'dimensionValues.0.value', '(direct)'),
                'medium' => data_get($row, 'dimensionValues.1.value', '(none)'),
                'sessions' => (int) data_get($row, 'metricValues.0.value', 0),
                'users' => (int) data_get($row, 'metricValues.1.value', 0),
            ];
        }

        return $sources;
    }

    /**
     * Parse GA4 pages response into a structured array.
     *
     * @return array<int, array{page_path: string, page_title: string|null, pageviews: int, users: int, bounce_rate: float, avg_engagement_time: int, engagement_rate: float}>
     */
    private function parsePages(array $response): array
    {
        $rows = data_get($response, 'rows', []);
        $pages = [];

        foreach ($rows as $row) {
            $pages[] = [
                'page_path' => data_get($row, 'dimensionValues.0.value', '/'),
                'page_title' => data_get($row, 'dimensionValues.1.value'),
                'pageviews' => (int) data_get($row, 'metricValues.0.value', 0),
                'users' => (int) data_get($row, 'metricValues.1.value', 0),
                'bounce_rate' => round((float) data_get($row, 'metricValues.2.value', 0) * 100, 2),
                'avg_engagement_time' => (int) round((float) data_get($row, 'metricValues.3.value', 0)),
                'engagement_rate' => round((float) data_get($row, 'metricValues.4.value', 0) * 100, 2),
            ];
        }

        return $pages;
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HasActiveProperty;
use App\Models\GaProperty;
use App\Services\GaClientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use HasActiveProperty;

    public function __construct(
        private GaClientService $ga,
    ) {}

    public function index(Request $request): Response
    {
        $property = $this->getActiveProperty($request);

        if (! $property) {
            $hasConnection = $request->user()->gaConnections()->where('is_active', true)->exists();

            return Inertia::render('dashboard/overview', [
                'overview' => null,
                'realtime' => 0,
                'property' => null,
                'hasProperty' => false,
                'hasConnection' => $hasConnection,
                'period' => '30d',
                'periods' => $this->periodLabels(),
            ]);
        }

        ['period' => $period, 'range' => $range] = $this->getDateRange($request);

        $overview = $this->ga->runReport($property, [
            'dateRanges' => [
                ['startDate' => $range['start'], 'endDate' => 'today'],
                ['startDate' => $range['compare'], 'endDate' => $range['compareEnd']],
            ],
            'dimensions' => [['name' => 'date']],
            'metrics' => [
                ['name' => 'activeUsers'],
                ['name' => 'sessions'],
                ['name' => 'bounceRate'],
                ['name' => 'averageSessionDuration'],
            ],
        ]);

        $todayReport = $this->ga->runReport($property, [
            'dateRanges' => [
                ['startDate' => 'today', 'endDate' => 'today'],
                ['startDate' => '7daysAgo', 'endDate' => '7daysAgo'],
            ],
            'metrics' => [
                ['name' => 'activeUsers'],
            ],
        ]);

        // Audience: countries
        $countries = $this->ga->runReport($property, [
            'dateRanges' => [['startDate' => $range['start'], 'endDate' => 'today']],
            'dimensions' => [['name' => 'country']],
            'metrics' => [['name' => 'activeUsers']],
            'limit' => 10,
            'orderBys' => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]],
        ]);

        // Audience: devices
        $devices = $this->ga->runReport($property, [
            'dateRanges' => [['startDate' => $range['start'], 'endDate' => 'today']],
            'dimensions' => [['name' => 'deviceCategory']],
            'metrics' => [['name' => 'activeUsers']],
        ]);

        // Top pages
        $pages = $this->ga->runReport($property, [
            'dateRanges' => [['startDate' => $range['start'], 'endDate' => 'today']],
            'dimensions' => [['name' => 'pagePath'], ['name' => 'pageTitle']],
            'metrics' => [['name' => 'screenPageViews'], ['name' => 'activeUsers']],
            'limit' => 10,
            'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
        ]);

        // Traffic channels
        $channels = $this->ga->runReport($property, [
            'dateRanges' => [['startDate' => $range['start'], 'endDate' => 'today']],
            'dimensions' => [['name' => 'sessionDefaultChannelGroup']],
            'metrics' => [['name' => 'sessions'], ['name' => 'activeUsers']],
            'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
        ]);

        // Top events
        $events = $this->ga->runReport($property, [
            'dateRanges' => [['startDate' => $range['start'], 'endDate' => 'today']],
            'dimensions' => [['name' => 'eventName']],
            'metrics' => [['name' => 'eventCount'], ['name' => 'totalUsers']],
            'limit' => 15,
            'orderBys' => [['metric' => ['metricName' => 'eventCount'], 'desc' => true]],
        ]);

        try {
            $realtime = $this->ga->runRealtimeReport($property, [
                'metrics' => [['name' => 'activeUsers']],
            ]);
            $realtimeUsers = (int) data_get($realtime, 'rows.0.metricValues.0.value', 0);
        } catch (\Throwable) {
            $realtimeUsers = 0;
        }

        return Inertia::render('dashboard/overview', [
            'overview' => $this->transformOverview($overview),
            'today' => $this->transformToday($todayReport),
            'countries' => $this->transformDimensionReport($countries),
            'devices' => $this->transformDimensionReport($devices),
            'pages' => $this->transformPagesReport($pages),
            'channels' => $this->transformDimensionReport($channels),
            'events' => $this->transformEventsReport($events),
            'realtime' => $realtimeUsers,
            'property' => $property->only('id', 'display_name', 'property_id'),
            'hasProperty' => true,
            'period' => $period,
            'periods' => array_map(fn ($p) => $p['label'], self::PERIODS),
        ]);
    }

    public function realtime(Request $request, GaProperty $property): JsonResponse
    {
        abort_unless($property->user_id === $request->user()->id, 403);

        try {
            $result = $this->ga->runRealtimeReport($property, [
                'metrics' => [['name' => 'activeUsers']],
            ]);

            return response()->json([
                'activeUsers' => (int) data_get($result, 'rows.0.metricValues.0.value', 0),
            ]);
        } catch (\Throwable) {
            return response()->json(['activeUsers' => 0]);
        }
    }

    /** @return array{kpis: array<string, mixed>, trend: array<int, array<string, mixed>>} */
    private function transformOverview(array $data): array
    {
        $rows = $data['rows'] ?? [];

        $currentRows = [];

        foreach ($rows as $row) {
            $entry = [
                'date' => $row['dimensionValues'][0]['value'] ?? '',
                'activeUsers' => (int) ($row['metricValues'][0]['value'] ?? 0),
                'sessions' => (int) ($row['metricValues'][1]['value'] ?? 0),
                'bounceRate' => (float) ($row['metricValues'][2]['value'] ?? 0),
                'avgSessionDuration' => (float) ($row['metricValues'][3]['value'] ?? 0),
            ];

            $currentRows[] = $entry;
        }

        // Build trend data
        $trend = collect($currentRows)
            ->sortBy('date')
            ->values()
            ->map(fn (array $row) => [
                'date' => substr($row['date'], 4, 2).'/'.substr($row['date'], 6, 2),
                'activeUsers' => $row['activeUsers'],
                'sessions' => $row['sessions'],
            ])
            ->toArray();

        // Aggregate KPIs
        $sumUsers = collect($currentRows)->sum('activeUsers');
        $sumSessions = collect($currentRows)->sum('sessions');
        $avgBounce = collect($currentRows)->avg('bounceRate');
        $avgDuration = collect($currentRows)->avg('avgSessionDuration');

        return [
            'kpis' => [
                'users' => $sumUsers,
                'sessions' => $sumSessions,
                'bounceRate' => round(($avgBounce ?? 0) * 100, 1),
                'avgSessionDuration' => $this->formatDuration($avgDuration ?? 0),
            ],
            'trend' => $trend,
        ];
    }

    /** @return array{usersToday: int, usersSameDayLastWeek: int} */
    private function transformToday(array $data): array
    {
        $rows = $data['rows'] ?? [];

        return [
            'usersToday' => (int) data_get($rows, '0.metricValues.0.value', 0),
            'usersSameDayLastWeek' => (int) data_get($rows, '1.metricValues.0.value', 0),
        ];
    }

    /** @return array<int, array{name: string, value: int, percent: float}> */
    private function transformDimensionReport(array $data): array
    {
        $rows = $data['rows'] ?? [];
        $total = collect($rows)->sum(fn ($row) => (int) ($row['metricValues'][0]['value'] ?? 0));

        return collect($rows)
            ->map(fn ($row) => [
                'name' => $row['dimensionValues'][0]['value'] ?? '(unknown)',
                'value' => (int) ($row['metricValues'][0]['value'] ?? 0),
                'percent' => $total > 0
                    ? round(((int) ($row['metricValues'][0]['value'] ?? 0)) / $total * 100, 1)
                    : 0,
            ])
            ->values()
            ->toArray();
    }

    /** @return array<int, array{path: string, title: string, views: int, users: int}> */
    private function transformPagesReport(array $data): array
    {
        return collect($data['rows'] ?? [])
            ->map(fn ($row) => [
                'path' => $row['dimensionValues'][0]['value'] ?? '/',
                'title' => $row['dimensionValues'][1]['value'] ?? '',
                'views' => (int) ($row['metricValues'][0]['value'] ?? 0),
                'users' => (int) ($row['metricValues'][1]['value'] ?? 0),
            ])
            ->values()
            ->toArray();
    }

    /** @return array<int, array{name: string, count: int, users: int, label: string}> */
    private function transformEventsReport(array $data): array
    {
        $labels = [
            'page_view' => 'Page View',
            'session_start' => 'Session Start',
            'first_visit' => 'First Visit',
            'scroll' => 'Scroll (90%)',
            'click' => 'Outbound Click',
            'user_engagement' => 'User Engagement',
            'view_search_results' => 'Site Search',
            'file_download' => 'File Download',
            'form_start' => 'Form Start',
            'form_submit' => 'Form Submit',
            'purchase' => 'Purchase',
            'add_to_cart' => 'Add to Cart',
            'begin_checkout' => 'Begin Checkout',
            'sign_up' => 'Sign Up',
            'login' => 'Login',
        ];

        return collect($data['rows'] ?? [])
            ->map(fn ($row) => [
                'name' => $row['dimensionValues'][0]['value'] ?? '',
                'count' => (int) ($row['metricValues'][0]['value'] ?? 0),
                'users' => (int) ($row['metricValues'][1]['value'] ?? 0),
                'label' => $labels[$row['dimensionValues'][0]['value'] ?? ''] ?? $row['dimensionValues'][0]['value'] ?? '',
            ])
            ->values()
            ->toArray();
    }

    private function formatDuration(float $seconds): string
    {
        $minutes = (int) floor($seconds / 60);
        $secs = (int) ($seconds % 60);

        if ($minutes > 0) {
            return "{$minutes}m {$secs}s";
        }

        return "{$secs}s";
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HasActiveProperty;
use App\Services\GaClientService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TrafficReportController extends Controller
{
    use HasActiveProperty;

    public function __construct(
        private GaClientService $ga,
    ) {}

    public function index(Request $request): Response
    {
        $property = $this->getActiveProperty($request);

        if (! $property) {
            return Inertia::render('reports/traffic', [
                'channels' => [],
                'sources' => [],
                'hasProperty' => false,
                'period' => '30d',
                'periods' => $this->periodLabels(),
            ]);
        }

        ['period' => $period, 'range' => $range] = $this->getDateRange($request);

        $channelsData = $this->ga->runReport($property, [
            'dateRanges' => [
                ['startDate' => $range['start'], 'endDate' => 'today'],
                ['startDate' => $range['compare'], 'endDate' => $range['compareEnd']],
            ],
            'dimensions' => [['name' => 'sessionDefaultChannelGroup']],
            'metrics' => [
                ['name' => 'sessions'],
                ['name' => 'activeUsers'],
                ['name' => 'bounceRate'],
                ['name' => 'averageSessionDuration'],
            ],
            'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
        ]);

        $sourcesData = $this->ga->runReport($property, [
            'dateRanges' => [['startDate' => $range['start'], 'endDate' => 'today']],
            'dimensions' => [['name' => 'sessionSource'], ['name' => 'sessionMedium']],
            'metrics' => [['name' => 'sessions'], ['name' => 'activeUsers']],
            'limit' => 20,
            'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
        ]);

        return Inertia::render('reports/traffic', [
            'channels' => $this->transformChannels($channelsData),
            'sources' => $this->transformSources($sourcesData),
            'hasProperty' => true,
            'period' => $period,
            'periods' => $this->periodLabels(),
        ]);
    }

    private function transformChannels(array $data): array
    {
        $rows = $data['rows'] ?? [];
        $totalSessions = collect($rows)->sum(fn ($r) => (int) ($r['metricValues'][0]['value'] ?? 0));

        return collect($rows)->map(fn ($row) => [
            'name' => $row['dimensionValues'][0]['value'] ?? '(unknown)',
            'sessions' => (int) ($row['metricValues'][0]['value'] ?? 0),
            'users' => (int) ($row['metricValues'][1]['value'] ?? 0),
            'bounceRate' => round((float) ($row['metricValues'][2]['value'] ?? 0) * 100, 1),
            'avgDuration' => round((float) ($row['metricValues'][3]['value'] ?? 0)),
            'percent' => $totalSessions > 0
                ? round(((int) ($row['metricValues'][0]['value'] ?? 0)) / $totalSessions * 100, 1)
                : 0,
        ])->values()->toArray();
    }

    private function transformSources(array $data): array
    {
        $rows = $data['rows'] ?? [];
        $totalSessions = collect($rows)->sum(fn ($r) => (int) ($r['metricValues'][0]['value'] ?? 0));

        return collect($rows)->map(fn ($row) => [
            'source' => $row['dimensionValues'][0]['value'] ?? '(direct)',
            'medium' => $row['dimensionValues'][1]['value'] ?? '(none)',
            'sessions' => (int) ($row['metricValues'][0]['value'] ?? 0),
            'users' => (int) ($row['metricValues'][1]['value'] ?? 0),
            'percent' => $totalSessions > 0
                ? round(((int) ($row['metricValues'][0]['value'] ?? 0)) / $totalSessions * 100, 1)
                : 0,
        ])->values()->toArray();
    }
}

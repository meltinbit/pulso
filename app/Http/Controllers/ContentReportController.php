<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HasActiveProperty;
use App\Services\GaClientService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContentReportController extends Controller
{
    use HasActiveProperty;

    public function __construct(
        private GaClientService $ga,
    ) {}

    public function index(Request $request): Response
    {
        $property = $this->getActiveProperty($request);

        if (! $property) {
            return Inertia::render('reports/content', [
                'pages' => [],
                'hasProperty' => false,
                'period' => '30d',
                'periods' => $this->periodLabels(),
            ]);
        }

        ['period' => $period, 'range' => $range] = $this->getDateRange($request);

        $pagesData = $this->ga->runReport($property, [
            'dateRanges' => [['startDate' => $range['start'], 'endDate' => 'today']],
            'dimensions' => [['name' => 'pagePath'], ['name' => 'pageTitle']],
            'metrics' => [
                ['name' => 'screenPageViews'],
                ['name' => 'activeUsers'],
                ['name' => 'bounceRate'],
                ['name' => 'averageSessionDuration'],
            ],
            'limit' => 50,
            'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
        ]);

        return Inertia::render('reports/content', [
            'pages' => $this->transformPages($pagesData),
            'hasProperty' => true,
            'period' => $period,
            'periods' => $this->periodLabels(),
        ]);
    }

    private function transformPages(array $data): array
    {
        return collect($data['rows'] ?? [])->map(fn ($row) => [
            'path' => $row['dimensionValues'][0]['value'] ?? '/',
            'title' => $row['dimensionValues'][1]['value'] ?? '',
            'views' => (int) ($row['metricValues'][0]['value'] ?? 0),
            'users' => (int) ($row['metricValues'][1]['value'] ?? 0),
            'bounceRate' => round((float) ($row['metricValues'][2]['value'] ?? 0) * 100, 1),
            'avgDuration' => $this->formatDuration((float) ($row['metricValues'][3]['value'] ?? 0)),
        ])->values()->toArray();
    }

    private function formatDuration(float $seconds): string
    {
        $minutes = (int) floor($seconds / 60);
        $secs = (int) ($seconds % 60);

        return $minutes > 0 ? "{$minutes}m {$secs}s" : "{$secs}s";
    }
}

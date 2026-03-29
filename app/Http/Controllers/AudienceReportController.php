<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HasActiveProperty;
use App\Services\GaClientService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AudienceReportController extends Controller
{
    use HasActiveProperty;

    public function __construct(
        private GaClientService $ga,
    ) {}

    public function index(Request $request): Response
    {
        $property = $this->getActiveProperty($request);

        if (! $property) {
            return Inertia::render('reports/audience', [
                'countries' => [],
                'devices' => [],
                'browsers' => [],
                'newVsReturning' => [],
                'hasProperty' => false,
                'period' => '30d',
                'periods' => $this->periodLabels(),
            ]);
        }

        ['period' => $period, 'range' => $range] = $this->getDateRange($request);
        $dateRange = [['startDate' => $range['start'], 'endDate' => 'today']];

        $countries = $this->ga->runReport($property, [
            'dateRanges' => $dateRange,
            'dimensions' => [['name' => 'country']],
            'metrics' => [['name' => 'activeUsers'], ['name' => 'sessions']],
            'limit' => 15,
            'orderBys' => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]],
        ]);

        $devices = $this->ga->runReport($property, [
            'dateRanges' => $dateRange,
            'dimensions' => [['name' => 'deviceCategory']],
            'metrics' => [['name' => 'activeUsers']],
        ]);

        $browsers = $this->ga->runReport($property, [
            'dateRanges' => $dateRange,
            'dimensions' => [['name' => 'browser']],
            'metrics' => [['name' => 'activeUsers']],
            'limit' => 8,
            'orderBys' => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]],
        ]);

        $newVsReturning = $this->ga->runReport($property, [
            'dateRanges' => $dateRange,
            'dimensions' => [['name' => 'newVsReturning']],
            'metrics' => [['name' => 'activeUsers']],
        ]);

        return Inertia::render('reports/audience', [
            'countries' => $this->transformDimension($countries),
            'devices' => $this->transformDimension($devices),
            'browsers' => $this->transformDimension($browsers),
            'newVsReturning' => $this->transformDimension($newVsReturning),
            'hasProperty' => true,
            'period' => $period,
            'periods' => $this->periodLabels(),
        ]);
    }

    private function transformDimension(array $data): array
    {
        $rows = $data['rows'] ?? [];
        $total = collect($rows)->sum(fn ($r) => (int) ($r['metricValues'][0]['value'] ?? 0));

        return collect($rows)->map(fn ($row) => [
            'name' => $row['dimensionValues'][0]['value'] ?? '(unknown)',
            'value' => (int) ($row['metricValues'][0]['value'] ?? 0),
            'extra' => (int) ($row['metricValues'][1]['value'] ?? 0),
            'percent' => $total > 0
                ? round(((int) ($row['metricValues'][0]['value'] ?? 0)) / $total * 100, 1)
                : 0,
        ])->values()->toArray();
    }
}

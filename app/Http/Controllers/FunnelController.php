<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HasActiveProperty;
use App\Http\Requests\StoreFunnelRequest;
use App\Models\Funnel;
use App\Models\FunnelStep;
use App\Services\GaClientService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class FunnelController extends Controller
{
    use HasActiveProperty;

    public function __construct(
        private GaClientService $ga,
    ) {}

    public function index(Request $request): Response
    {
        $property = $this->getActiveProperty($request);

        $funnels = $property
            ? Funnel::where('user_id', $request->user()->id)
                ->where('ga_property_id', $property->id)
                ->withCount('steps')
                ->orderByDesc('id')
                ->get()
            : collect();

        return Inertia::render('funnels/index', [
            'funnels' => $funnels,
            'hasProperty' => (bool) $property,
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('funnels/create', [
            'hasProperty' => (bool) $this->getActiveProperty($request),
        ]);
    }

    public function store(StoreFunnelRequest $request): RedirectResponse
    {
        $property = $this->getActiveProperty($request);
        abort_unless($property, 404);

        $validated = $request->validated();

        $funnel = DB::transaction(function () use ($request, $property, $validated) {
            $funnel = Funnel::create([
                'user_id' => $request->user()->id,
                'ga_property_id' => $property->id,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'is_open' => $validated['is_open'] ?? false,
            ]);

            foreach ($validated['steps'] as $i => $step) {
                FunnelStep::create([
                    'funnel_id' => $funnel->id,
                    'order' => $i + 1,
                    'name' => $step['name'],
                    'event_name' => $step['event_name'],
                ]);
            }

            return $funnel;
        });

        return redirect()->route('funnels.show', $funnel)
            ->with('success', 'Funnel created.');
    }

    public function show(Request $request, Funnel $funnel): Response
    {
        Gate::authorize('view', $funnel);

        $property = $funnel->gaProperty;
        ['period' => $period, 'range' => $range] = $this->getDateRange($request);

        try {
            $result = $this->ga->runFunnelReport($property, [
                'dateRanges' => [
                    ['startDate' => $range['start'], 'endDate' => 'today'],
                ],
                'funnel' => [
                    'isOpenFunnel' => $funnel->is_open,
                    'steps' => $funnel->steps->map(fn (FunnelStep $step) => [
                        'name' => $step->name,
                        'filterExpression' => [
                            'funnelEventFilter' => [
                                'eventName' => $step->event_name,
                            ],
                        ],
                    ])->toArray(),
                ],
            ]);

            $results = $this->transformFunnelResults($result);
        } catch (\Throwable $e) {
            $results = [];
        }

        return Inertia::render('funnels/show', [
            'funnel' => $funnel->load('steps'),
            'results' => $results,
            'period' => $period,
            'periods' => $this->periodLabels(),
        ]);
    }

    public function destroy(Funnel $funnel): RedirectResponse
    {
        Gate::authorize('delete', $funnel);

        $funnel->delete();

        return redirect()->route('funnels.index')
            ->with('success', 'Funnel deleted.');
    }

    /** @return array<int, array{step: string, users: int, abandonments: int, abandonment_rate: float}> */
    private function transformFunnelResults(array $result): array
    {
        $rows = $result['funnelVisualization']['rows'] ?? [];

        return array_map(fn ($row) => [
            'step' => $row['dimensionValues'][0]['value'] ?? '',
            'users' => (int) ($row['metricValues'][0]['value'] ?? 0),
            'abandonments' => (int) ($row['metricValues'][1]['value'] ?? 0),
            'abandonment_rate' => round((float) ($row['metricValues'][2]['value'] ?? 0) * 100, 1),
        ], $rows);
    }
}

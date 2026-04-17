<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HasActiveProperty;
use App\Services\SnapshotAnalyzerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class SnapshotController extends Controller
{
    use HasActiveProperty;

    public function index(Request $request): Response
    {
        $property = $this->getActiveProperty($request);

        $snapshots = $property
            ? $property->snapshots()
                ->with('sources', 'pages', 'searchQueries')
                ->orderByDesc('snapshot_date')
                ->paginate(30)
            : null;

        return Inertia::render('snapshots/index', [
            'hasProperty' => $property !== null,
            'property' => $property?->only('id', 'display_name', 'website_url'),
            'snapshots' => $snapshots,
        ]);
    }

    public function generate(Request $request, SnapshotAnalyzerService $analyzer): RedirectResponse
    {
        $property = $this->getActiveProperty($request);

        if (! $property) {
            return back()->with('error', 'Nessuna proprietà attiva.');
        }

        $date = Carbon::yesterday();

        try {
            $snapshot = $analyzer->analyze($property, $date);

            return back()->with('success', "Snapshot generato per {$date->toDateString()}: {$snapshot->trend} (score: {$snapshot->trend_score})");
        } catch (\Throwable $e) {
            Log::warning("Manual snapshot failed for {$property->display_name}: {$e->getMessage()}");

            return back()->with('error', "Errore: {$e->getMessage()}");
        }
    }
}

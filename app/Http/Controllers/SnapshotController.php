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

        $targetDate = Carbon::yesterday();
        
        // Find the latest snapshot for this property
        $latestSnapshot = $property->snapshots()
            ->latest('snapshot_date')
            ->first();
        
        $snapshotsGenerated = 0;
        $messages = [];
        
        try {
            if ($latestSnapshot) {
                $lastSnapshotDate = Carbon::parse($latestSnapshot->snapshot_date);
                
                // If there's a gap between the latest snapshot and yesterday
                if ($lastSnapshotDate->lt($targetDate)) {
                    // Create snapshots for each missing day
                    $currentDate = $lastSnapshotDate->copy()->addDay();
                    
                    while ($currentDate->lte($targetDate)) {
                        $snapshot = $analyzer->analyze($property, $currentDate);
                        $messages[] = "Snapshot generato per {$currentDate->toDateString()}: {$snapshot->trend} (score: {$snapshot->trend_score})";
                        $snapshotsGenerated++;
                        $currentDate->addDay();
                    }
                } else {
                    // If no gap, just create for yesterday if not already created
                    if (!$lastSnapshotDate->isSameDay($targetDate)) {
                        $snapshot = $analyzer->analyze($property, $targetDate);
                        $messages[] = "Snapshot generato per {$targetDate->toDateString()}: {$snapshot->trend} (score: {$snapshot->trend_score})";
                        $snapshotsGenerated++;
                    } else {
                        // Update the snapshot for yesterday
                        $snapshot = $analyzer->analyze($property, $targetDate);
                        $messages[] = "Snapshot aggiornato per {$targetDate->toDateString()}: {$snapshot->trend} (score: {$snapshot->trend_score})";
                        $snapshotsGenerated++;
                    }
                }
            } else {
                // No previous snapshots, create one for yesterday
                $snapshot = $analyzer->analyze($property, $targetDate);
                $messages[] = "Snapshot generato per {$targetDate->toDateString()}: {$snapshot->trend} (score: {$snapshot->trend_score})";
                $snapshotsGenerated++;
            }
            
            if ($snapshotsGenerated > 1) {
                return back()->with('success', "Generati $snapshotsGenerated snapshots per recuperare i dati mancanti.");
            } else {
                return back()->with('success', $messages[0]);
            }
        } catch (\Throwable $e) {
            Log::warning("Manual snapshot failed for {$property->display_name}: {$e->getMessage()}");
            return back()->with('error', "Errore: {$e->getMessage()}");
        }
    }
}

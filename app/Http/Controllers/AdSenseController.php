<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HasActiveProperty;
use App\Jobs\BackfillAdSenseData;
use App\Models\GaProperty;
use App\Services\AdSenseReportService;
use App\Services\AdSenseSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class AdSenseController extends Controller
{
    use HasActiveProperty;

    public function __construct(
        private AdSenseReportService $reports,
    ) {}

    public function index(Request $request): Response
    {
        $property = $this->getActiveProperty($request);
        ['period' => $period] = $this->getDateRange($request);

        $status = $property ? $this->status($property) : null;
        $to = Carbon::today($property?->timezone ?: 'UTC');
        $from = $to->copy()->subDays((int) $period - 1);

        return Inertia::render('adsense/index', [
            'hasProperty' => $property !== null,
            'property' => $property?->only('id', 'display_name', 'website_url'),
            'status' => $status,
            'period' => $period,
            'periods' => $this->periodLabels(),
            'report' => $status === 'ready' ? $this->reports->summarize($property, $from, $to) : null,
        ]);
    }

    public function sync(Request $request, AdSenseSyncService $sync): RedirectResponse
    {
        $property = $this->getActiveProperty($request);

        if (! $property) {
            return back()->with('error', 'Nessuna proprietà attiva.');
        }

        if (in_array($this->status($property), ['missing_scope', 'missing_website'], true)) {
            return back()->with('error', 'AdSense non è configurato per questa proprietà.');
        }

        $isFirstSync = ! $this->reports->hasData($property);

        try {
            $stored = $sync->syncRecent($property);
        } catch (\Throwable $e) {
            Log::warning("Manual AdSense sync failed for {$property->display_name}: {$e->getMessage()}");

            return back()->with('error', "Errore: {$e->getMessage()}");
        }

        if ($isFirstSync) {
            BackfillAdSenseData::dispatch($property);

            return back()->with('success', "Sincronizzati gli ultimi {$stored} giorni. Lo storico di ".AdSenseSyncService::BACKFILL_MONTHS.' mesi è in download in background.');
        }

        return back()->with('success', "Dati AdSense aggiornati ({$stored} giorni).");
    }

    /**
     * @return 'missing_scope'|'missing_website'|'no_data'|'ready'
     */
    private function status(GaProperty $property): string
    {
        return match (true) {
            ! $property->gaConnection?->hasAdSenseScope() => 'missing_scope',
            blank($property->website_url) => 'missing_website',
            ! $this->reports->hasData($property) => 'no_data',
            default => 'ready',
        };
    }
}

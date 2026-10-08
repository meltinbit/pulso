<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HasActiveProperty;
use App\Jobs\BackfillAdSenseData;
use App\Models\GaProperty;
use App\Services\AdSenseReportService;
use App\Services\AdSenseService;
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

    public function index(Request $request, AdSenseSyncService $sync): Response
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
            'backfill' => $property ? $sync->backfillStatus($property) : null,
        ]);
    }

    public function sync(Request $request, AdSenseSyncService $sync, AdSenseService $adSense): RedirectResponse
    {
        $property = $this->getActiveProperty($request);

        if (! $property) {
            return back()->with('error', 'Nessuna proprietà attiva.');
        }

        if (in_array($this->status($property), ['missing_scope', 'missing_website'], true)) {
            return back()->with('error', 'AdSense non è configurato per questa proprietà.');
        }

        $isFirstSync = ! $this->reports->hasData($property);
        $recentDays = AdSenseSyncService::RECENT_DAYS;

        try {
            $stored = $sync->syncRecent($property);

            if ($isFirstSync && $stored === 0) {
                $domainError = $this->missingDomainError($property, $adSense);

                if ($domainError) {
                    return back()->with('error', $domainError);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Manual AdSense sync failed for {$property->display_name}: {$e->getMessage()}");

            return back()->with('error', "Errore: {$e->getMessage()}");
        }

        if ($isFirstSync) {
            $sync->markBackfill($property, 'queued');
            BackfillAdSenseData::dispatch($property);

            return back()->with('success', "Ultimi {$recentDays} giorni sincronizzati ({$stored} con dati). Lo storico di ".AdSenseSyncService::BACKFILL_MONTHS.' mesi è in coda per il download in background.');
        }

        return back()->with('success', "Dati AdSense aggiornati: ultimi {$recentDays} giorni ({$stored} con dati).");
    }

    /**
     * When the property's domain never shows up in the AdSense account, explain
     * which domains do, instead of queueing a backfill that will find nothing.
     */
    private function missingDomainError(GaProperty $property, AdSenseService $adSense): ?string
    {
        $host = $adSense->propertyHost($property);
        $to = Carbon::today($property->timezone ?: 'UTC');
        $from = $to->copy()->subMonthsNoOverflow(AdSenseSyncService::BACKFILL_MONTHS);

        $domains = $adSense->listReportedDomains($property, $from->toDateString(), $to->toDateString());

        if (in_array($host, $domains, true)) {
            return null;
        }

        $months = AdSenseSyncService::BACKFILL_MONTHS;

        return $domains === []
            ? "L'account AdSense non ha dati negli ultimi {$months} mesi."
            : "AdSense non ha dati per {$host} negli ultimi {$months} mesi. Domini con dati nell'account: ".implode(', ', $domains).'. Seleziona la proprietà corrispondente o correggi il suo URL del sito.';
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

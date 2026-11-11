<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\BingApiException;
use App\Http\Controllers\Controller;
use App\Jobs\SyncBingWebmasterData;
use App\Models\GaProperty;
use App\Services\BingSyncService;
use App\Services\BingWebmasterService;
use App\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BingSettingsController extends Controller
{
    public function __construct(
        private SettingService $settings,
        private BingWebmasterService $bing,
        private BingSyncService $sync,
    ) {}

    public function edit(Request $request): Response
    {
        $user = $request->user();
        $apiKey = $this->sync->apiKey($user);
        $sites = [];
        $error = null;

        if ($apiKey !== null) {
            try {
                $sites = $this->sync->matchSites($user, $apiKey);
            } catch (BingApiException $e) {
                $error = $e->getMessage();
            }
        }

        return Inertia::render('settings/bing', [
            'hasKey' => $apiKey !== null,
            'sites' => $sites,
            'error' => $error,
            'properties' => $user->gaProperties()
                ->where('is_active', true)
                ->withMax('bingTraffic', 'date')
                ->orderBy('display_name')
                ->get(['id', 'display_name', 'website_url', 'bing_site_url'])
                ->map(fn (GaProperty $property): array => [
                    'id' => $property->id,
                    'display_name' => $property->display_name,
                    'website_url' => $property->website_url,
                    'bing_site_url' => $property->bing_site_url,
                    'last_bing_day' => $property->bing_traffic_max_date ? substr((string) $property->bing_traffic_max_date, 0, 10) : null,
                ]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'bing_api_key' => ['required', 'string', 'max:255'],
        ]);

        try {
            $this->bing->userSites($validated['bing_api_key']);
        } catch (BingApiException $e) {
            throw ValidationException::withMessages(['bing_api_key' => $e->getMessage()]);
        }

        $userId = $request->user()->id;
        $this->settings->set($userId, BingSyncService::SETTING_KEY, $validated['bing_api_key'], 'bing', encrypted: true);
        $this->settings->set($userId, BingSyncService::ALERTED_SETTING_KEY, null, 'bing');

        return back()->with('success', 'API key Bing salvata.');
    }

    public function updateProperty(Request $request, GaProperty $property): RedirectResponse
    {
        abort_unless($property->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'bing_site_url' => ['nullable', 'url', 'max:255'],
        ]);

        $property->update(['bing_site_url' => $validated['bing_site_url'] ?? null]);

        return back()->with('success', 'Sito Bing aggiornato.');
    }

    public function sync(): RedirectResponse
    {
        SyncBingWebmasterData::dispatch();

        return back()->with('success', 'Sincronizzazione Bing avviata: i dati arrivano in qualche minuto.');
    }
}

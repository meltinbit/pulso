<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\PageExperienceService;
use App\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GoogleSettingsController extends Controller
{
    public function __construct(
        private SettingService $settings,
    ) {}

    public function edit(Request $request): Response
    {
        $userId = $request->user()->id;
        $googleSettings = $this->settings->getGroup($userId, 'google');

        return Inertia::render('settings/google', [
            'settings' => [
                'google_client_id' => $googleSettings['google_client_id'] ?? '',
                'google_client_secret' => ! empty($googleSettings['google_client_secret']) ? '********' : '',
            ],
            'connections' => $request->user()
                ->gaConnections()
                ->select('id', 'google_email', 'google_name', 'is_active', 'authorized_at', 'created_at')
                ->withCount('properties')
                ->orderByDesc('id')
                ->get(),
            'hasCredentials' => ! empty($googleSettings['google_client_id']) && ! empty($googleSettings['google_client_secret']),
            'hasApiKey' => ! empty($googleSettings[PageExperienceService::API_KEY_SETTING]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'google_client_id' => ['required', 'string'],
            'google_client_secret' => ['required', 'string'],
        ]);

        $userId = $request->user()->id;

        $this->settings->set($userId, 'google_client_id', $validated['google_client_id'], 'google', encrypted: true);
        $this->settings->set($userId, 'google_client_secret', $validated['google_client_secret'], 'google', encrypted: true);

        return back()->with('success', 'Credenziali Google salvate.');
    }

    /**
     * API key of the Google Cloud project, used for the Chrome UX Report and
     * PageSpeed Insights APIs. An empty value removes it.
     */
    public function updateApiKey(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'google_api_key' => ['nullable', 'string', 'max:255'],
        ]);

        $key = filled($validated['google_api_key'] ?? null) ? trim($validated['google_api_key']) : null;

        $this->settings->set($request->user()->id, PageExperienceService::API_KEY_SETTING, $key, 'google', encrypted: true);

        return back()->with('success', $key === null ? 'API key Google rimossa.' : 'API key Google salvata.');
    }
}

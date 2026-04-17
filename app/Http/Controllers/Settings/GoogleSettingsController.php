<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
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
                ->select('id', 'google_email', 'google_name', 'is_active', 'created_at')
                ->orderByDesc('id')
                ->get(),
            'hasCredentials' => ! empty($googleSettings['google_client_id']) && ! empty($googleSettings['google_client_secret']),
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
}

<?php

namespace App\Http\Controllers\Settings;

use App\Exceptions\MetaAdsApiException;
use App\Http\Controllers\Controller;
use App\Services\MetaAdsService;
use App\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MetaSettingsController extends Controller
{
    public function __construct(
        private SettingService $settings,
        private MetaAdsService $meta,
    ) {}

    public function edit(Request $request): Response
    {
        $user = $request->user();
        $hasToken = $this->meta->token($user) !== null;
        $accounts = [];
        $error = null;

        if ($hasToken) {
            try {
                $accounts = $this->meta->accounts($user);
            } catch (MetaAdsApiException $e) {
                $error = $e->getMessage();
            }
        }

        return Inertia::render('settings/meta', [
            'hasToken' => $hasToken,
            'accounts' => $accounts,
            'defaultAccount' => $this->settings->get($user->id, MetaAdsService::DEFAULT_ACCOUNT_SETTING_KEY),
            'error' => $error,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'meta_access_token' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $accounts = $this->meta->accountsForToken($validated['meta_access_token']);
        } catch (MetaAdsApiException $e) {
            throw ValidationException::withMessages(['meta_access_token' => $e->getMessage()]);
        }

        $userId = $request->user()->id;
        $this->settings->set($userId, MetaAdsService::SETTING_KEY, $validated['meta_access_token'], 'meta', encrypted: true);
        $this->settings->set($userId, MetaAdsService::INVALID_SETTING_KEY, null, 'meta');

        if (count($accounts) === 1) {
            $this->settings->set($userId, MetaAdsService::DEFAULT_ACCOUNT_SETTING_KEY, $accounts[0]['id'], 'meta');
        }

        return back()->with('success', 'Token Meta salvato: '.count($accounts).' account pubblicitari raggiungibili.');
    }

    public function updateDefaultAccount(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'account_id' => ['nullable', 'string', 'max:30'],
        ]);

        $this->settings->set(
            $request->user()->id,
            MetaAdsService::DEFAULT_ACCOUNT_SETTING_KEY,
            MetaAdsService::normalizeAccountId($validated['account_id'] ?? null),
            'meta',
        );

        return back()->with('success', 'Account Meta di default aggiornato.');
    }
}

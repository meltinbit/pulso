<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class TelegramSettingsController extends Controller
{
    public function __construct(
        private SettingService $settings,
    ) {}

    public function edit(Request $request): Response
    {
        $userId = $request->user()->id;
        $token = $this->settings->get($userId, 'telegram_bot_token');

        return Inertia::render('settings/telegram', [
            'settings' => [
                'telegram_bot_token' => $token ? str_repeat('*', 8) : '',
                'telegram_chat_id' => $this->settings->get($userId, 'telegram_chat_id', ''),
            ],
            'isConfigured' => $token !== null && $token !== '',
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'telegram_bot_token' => ['nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['nullable', 'string', 'max:50'],
        ]);

        $userId = $request->user()->id;

        if ($validated['telegram_bot_token'] && $validated['telegram_bot_token'] !== str_repeat('*', 8)) {
            $this->settings->set($userId, 'telegram_bot_token', $validated['telegram_bot_token'], 'telegram', true);
        }

        $this->settings->set($userId, 'telegram_chat_id', $validated['telegram_chat_id'], 'telegram');

        return back()->with('success', 'Telegram settings saved.');
    }

    public function test(Request $request): RedirectResponse
    {
        $userId = $request->user()->id;
        $token = $this->settings->get($userId, 'telegram_bot_token');
        $chatId = $this->settings->get($userId, 'telegram_chat_id');

        if (! $token || ! $chatId) {
            return back()->with('error', 'Configure bot token and chat ID first.');
        }

        $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => "\xE2\x9C\x85 Pulso — Test message. Telegram integration is working!",
        ]);

        if ($response->successful()) {
            return back()->with('success', 'Test message sent!');
        }

        return back()->with('error', 'Failed: '.$response->json('description', 'Unknown error'));
    }
}

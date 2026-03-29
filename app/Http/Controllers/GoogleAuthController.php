<?php

namespace App\Http\Controllers;

use App\Models\GaConnection;
use App\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function __construct(
        private SettingService $settings,
    ) {}

    public function redirect(): RedirectResponse
    {
        $this->configureGoogleDriver();

        return Socialite::driver('google')
            ->scopes([
                'https://www.googleapis.com/auth/analytics.readonly',
                'https://www.googleapis.com/auth/analytics.edit',
            ])
            ->with(['access_type' => 'offline', 'prompt' => 'consent'])
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->configureGoogleDriver();

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Exception $e) {
            return redirect()->route('settings.google')
                ->with('error', 'Autorizzazione Google annullata o fallita.');
        }

        GaConnection::updateOrCreate(
            ['user_id' => $request->user()->id, 'google_id' => $googleUser->getId()],
            [
                'google_email' => $googleUser->getEmail(),
                'google_name' => $googleUser->getName(),
                'access_token' => $googleUser->token,
                'refresh_token' => $googleUser->refreshToken,
                'token_expires_at' => now()->addSeconds($googleUser->expiresIn),
                'is_active' => true,
            ]
        );

        return redirect()->route('settings.google')
            ->with('success', 'Account Google collegato correttamente.');
    }

    public function disconnect(GaConnection $connection): RedirectResponse
    {
        abort_unless($connection->user_id === auth()->id(), 403);

        $connection->update(['is_active' => false]);

        return back()->with('success', 'Account Google scollegato.');
    }

    private function configureGoogleDriver(): void
    {
        config([
            'services.google.client_id' => $this->settings->get('google_client_id'),
            'services.google.client_secret' => $this->settings->get('google_client_secret'),
            'services.google.redirect' => url('/auth/google/callback'),
        ]);
    }
}

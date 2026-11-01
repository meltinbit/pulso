<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Laravel\Prompts\text;

class GoogleAdsAuthCommand extends Command
{
    protected $signature = 'pulso:google-ads-auth
        {--port=8765 : Loopback port used as OAuth redirect URI}';

    protected $description = 'Generate a Google Ads API refresh token (adwords scope) for GOOGLE_ADS_REFRESH_TOKEN';

    private const SCOPE = 'https://www.googleapis.com/auth/adwords';

    public function handle(): int
    {
        $clientId = config('services.google_ads.client_id');
        $clientSecret = config('services.google_ads.client_secret');

        if (blank($clientId) || blank($clientSecret)) {
            $this->error('Imposta prima GOOGLE_ADS_CLIENT_ID e GOOGLE_ADS_CLIENT_SECRET nel file .env (client OAuth di tipo "Desktop app").');

            return self::FAILURE;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Questo comando va lanciato in un terminale interattivo: deve chiederti di incollare l\'URL di redirect.');

            return self::FAILURE;
        }

        $redirectUri = 'http://127.0.0.1:'.(int) $this->option('port');
        $state = Str::random(24);

        $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        $this->line('1. Apri questo URL nel browser e accedi con l\'account Google che gestisce l\'MCC:');
        $this->newLine();
        $this->line($authUrl);
        $this->newLine();
        $this->line("2. Dopo il consenso il browser andrà su {$redirectUri} e mostrerà un errore di connessione: è normale.");
        $this->line('3. Copia l\'intero URL dalla barra degli indirizzi e incollalo qui sotto.');
        $this->newLine();

        $pasted = text(label: 'URL di redirect (o solo il codice)', required: true);

        [$code, $returnedState] = $this->parseRedirect($pasted);

        if ($returnedState !== null && $returnedState !== $state) {
            $this->error('Il parametro state non corrisponde: rilancia il comando e usa l\'URL appena generato.');

            return self::FAILURE;
        }

        if (blank($code)) {
            $this->error('Nessun codice di autorizzazione trovato nell\'URL incollato.');

            return self::FAILURE;
        }

        $response = Http::asForm()
            ->timeout(15)
            ->connectTimeout(5)
            ->post('https://oauth2.googleapis.com/token', [
                'code' => $code,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ]);

        $refreshToken = $response->json('refresh_token');

        if ($response->failed() || blank($refreshToken)) {
            $this->error('Scambio del codice fallito: '.($response->json('error_description') ?? $response->json('error') ?? 'nessun refresh token restituito').'.');
            $this->line('Controlla che il client OAuth sia di tipo "Desktop app" e rilancia il comando: ogni codice vale una sola volta.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Refresh token generato. Aggiungi questa riga al file .env (e alle variabili su Coolify):');
        $this->newLine();
        $this->line("GOOGLE_ADS_REFRESH_TOKEN={$refreshToken}");

        return self::SUCCESS;
    }

    /**
     * Extract the authorization code and state from a pasted redirect URL or bare code.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function parseRedirect(string $pasted): array
    {
        $pasted = trim($pasted);

        if (! str_contains($pasted, 'code=')) {
            return [$pasted !== '' ? urldecode($pasted) : null, null];
        }

        parse_str((string) (parse_url($pasted, PHP_URL_QUERY) ?? Str::after($pasted, '?')), $query);

        return [
            isset($query['code']) ? (string) $query['code'] : null,
            isset($query['state']) ? (string) $query['state'] : null,
        ];
    }
}

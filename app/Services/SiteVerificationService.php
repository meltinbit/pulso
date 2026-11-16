<?php

namespace App\Services;

use App\Exceptions\SearchConsoleApiException;
use App\Models\GaConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Google Site Verification API: proves that a Google account owns a site, the
 * step Search Console needs before it shows any data for a new site.
 */
class SiteVerificationService
{
    private const API = 'https://www.googleapis.com/siteVerification/v1/';

    /** Methods per site type: a Domain property is verified only through DNS. */
    public const METHODS = [
        'INET_DOMAIN' => ['DNS_TXT', 'DNS_CNAME'],
        'SITE' => ['META', 'FILE', 'ANALYTICS', 'TAG_MANAGER', 'DNS_TXT', 'DNS_CNAME'],
    ];

    public function __construct(
        private GoogleTokenService $tokenService,
    ) {}

    /**
     * Parse what the user typed into a Search Console site: a bare host or
     * sc-domain:host is a Domain property, a URL is a URL-prefix property.
     *
     * @return array{type: string, identifier: string, site_url: string, host: string}
     */
    public static function parseSite(string $site): array
    {
        $site = trim($site);

        if (preg_match('#^https?://#i', $site)) {
            $host = strtolower((string) parse_url($site, PHP_URL_HOST));
            $url = strtolower((string) parse_url($site, PHP_URL_SCHEME)).'://'.$host.(parse_url($site, PHP_URL_PATH) ?: '/');
            $url = str_ends_with($url, '/') ? $url : $url.'/';

            return ['type' => 'SITE', 'identifier' => $url, 'site_url' => $url, 'host' => $host];
        }

        $host = strtolower(rtrim(Str::after($site, 'sc-domain:'), '/'));

        return ['type' => 'INET_DOMAIN', 'identifier' => $host, 'site_url' => "sc-domain:{$host}", 'host' => $host];
    }

    /**
     * The token to publish (TXT record, CNAME, meta tag or file name),
     * depending on the method.
     *
     * @throws SearchConsoleApiException
     */
    public function token(GaConnection $connection, string $type, string $identifier, string $method): string
    {
        $response = $this->request($connection)->post(self::API.'token', [
            'site' => ['type' => $type, 'identifier' => $identifier],
            'verificationMethod' => $method,
        ]);

        $this->throwIfFailed($response, 'richiesta del codice di verifica');

        return (string) $response->json('token');
    }

    /**
     * Ask Google to check the token now; on success the account becomes a
     * verified owner of the site.
     *
     * @throws SearchConsoleApiException
     */
    public function verify(GaConnection $connection, string $type, string $identifier, string $method): void
    {
        $response = $this->request($connection)->post(self::API.'webResource?verificationMethod='.$method, [
            'site' => ['type' => $type, 'identifier' => $identifier],
        ]);

        if ($response->status() === 400) {
            throw new SearchConsoleApiException("Google non ha trovato il codice di verifica per {$identifier} con il metodo {$method}: controlla che sia pubblicato (un record DNS può impiegare da qualche minuto a qualche ora a propagarsi) e riprova. Dettaglio di Google: ".($response->json('error.message') ?? ''));
        }

        $this->throwIfFailed($response, 'verifica del sito');
    }

    private function request(GaConnection $connection): PendingRequest
    {
        return Http::withToken($this->tokenService->getFreshToken($connection))
            ->acceptJson()
            ->timeout(30)
            ->connectTimeout(5);
    }

    /**
     * @throws SearchConsoleApiException
     */
    private function throwIfFailed(Response $response, string $action): void
    {
        if ($response->successful()) {
            return;
        }

        $message = (string) ($response->json('error.message') ?? Str::limit($response->body(), 200));

        if ($response->status() === 403 && Str::contains($message, ['insufficient', 'scope'], ignoreCase: true)) {
            throw new SearchConsoleApiException("L'account Google non ha il permesso di verificare siti: ricollegalo in Impostazioni → Google di Pulso ({$action}).");
        }

        throw new SearchConsoleApiException("Errore Google nella {$action} (HTTP {$response->status()}): {$message}");
    }
}

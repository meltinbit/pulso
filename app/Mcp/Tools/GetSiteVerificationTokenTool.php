<?php

namespace App\Mcp\Tools;

use App\Exceptions\SearchConsoleApiException;
use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Services\SiteVerificationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('First step to add a new site to Google Search Console: returns the verification code to publish and where. site is a bare domain (example.com, a Domain property covering every subdomain and protocol, verified only by DNS) or a URL (https://www.example.com/, a URL-prefix property). Methods: DNS_TXT (default for domains: a TXT record on the domain), DNS_CNAME, META (default for URLs: a meta tag in the <head> of the home page), FILE (an HTML file at the site root), ANALYTICS or TAG_MANAGER (no code: the home page already loads the GA4 or GTM tag of an account the Google user can edit). Once the code is live, call add-search-console-site with the same site and method.')]
#[IsReadOnly]
#[IsIdempotent]
class GetSiteVerificationTokenTool extends Tool
{
    use ResolvesMcpContext;

    public function __construct(
        private SiteVerificationService $verification,
    ) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'site' => 'required|string|max:255',
            'method' => 'nullable|in:DNS_TXT,DNS_CNAME,META,FILE,ANALYTICS,TAG_MANAGER',
            'google_account' => 'nullable|string|max:255',
        ]);

        $site = SiteVerificationService::parseSite($validated['site']);
        $method = $validated['method'] ?? ($site['type'] === 'INET_DOMAIN' ? 'DNS_TXT' : 'META');

        if (! in_array($method, SiteVerificationService::METHODS[$site['type']], true)) {
            return Response::error("Un dominio intero ({$site['host']}) si verifica solo via DNS (DNS_TXT o DNS_CNAME): per META o FILE passa l'URL, per esempio https://{$site['host']}/.");
        }

        $connection = $this->resolveGoogleConnection($validated['google_account'] ?? null);

        if (is_string($connection)) {
            return Response::error($connection);
        }

        try {
            $token = $this->verification->token($connection, $site['type'], $site['identifier'], $method);
        } catch (SearchConsoleApiException $e) {
            return Response::error($e->getMessage());
        }

        return Response::text(json_encode([
            'site' => $site['site_url'],
            'google_account' => $connection->google_email,
            'method' => $method,
            'token' => $token,
            'instructions' => $this->instructions($method, $token, $site['host']),
            'next_step' => 'When the code is live, call add-search-console-site with the same site and method.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function instructions(string $method, string $token, string $host): string
    {
        return match ($method) {
            'DNS_TXT' => "Add a TXT record on {$host} (name @ or empty) with value {$token}. Keep it after verification: removing it unverifies the site.",
            'DNS_CNAME' => "Add the CNAME record described by the token (record name, then target): {$token}.",
            'META' => "Put this tag in the <head> of the home page and keep it there: {$token}",
            'FILE' => "Publish a file named {$token} at the site root, with the content: google-site-verification: {$token}",
            default => 'No code to publish: the home page must already load the '.($method === 'ANALYTICS' ? 'GA4 (gtag.js)' : 'Google Tag Manager').' tag of a container or property the Google account can edit.',
        };
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()
                ->description('example.com for a Domain property, or https://www.example.com/ for a URL-prefix property.')
                ->required(),
            'method' => $schema->string()
                ->enum(['DNS_TXT', 'DNS_CNAME', 'META', 'FILE', 'ANALYTICS', 'TAG_MANAGER'])
                ->description('Verification method. Defaults to DNS_TXT for a domain and META for a URL.'),
            'google_account' => $schema->string()
                ->description('Email of the Google account connected in Pulso that will own the site. Needed only when several accounts are connected.'),
        ];
    }
}

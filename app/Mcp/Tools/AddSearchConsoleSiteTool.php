<?php

namespace App\Mcp\Tools;

use App\Exceptions\SearchConsoleApiException;
use App\Jobs\BackfillSearchConsoleData;
use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Models\GaProperty;
use App\Services\BingSyncService;
use App\Services\SearchConsoleService;
use App\Services\SiteVerificationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Second step to add a new site to Google Search Console, after get-site-verification-token: asks Google to verify the published code, adds the site to Search Console, and links it in Pulso (to the existing property with the same domain, or as a new Search Console only property whose history is downloaded in the background). Optionally submits a sitemap. Safe to call again: an already verified site is just confirmed. If the code is not found yet (DNS can take minutes to hours), wait and retry.')]
#[IsIdempotent]
class AddSearchConsoleSiteTool extends Tool
{
    use ResolvesMcpContext;

    public function __construct(
        private SiteVerificationService $verification,
        private SearchConsoleService $searchConsole,
    ) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'site' => 'required|string|max:255',
            'method' => 'nullable|in:DNS_TXT,DNS_CNAME,META,FILE,ANALYTICS,TAG_MANAGER',
            'google_account' => 'nullable|string|max:255',
            'sitemap_url' => 'nullable|url|max:2048',
        ]);

        $site = SiteVerificationService::parseSite($validated['site']);
        $method = $validated['method'] ?? ($site['type'] === 'INET_DOMAIN' ? 'DNS_TXT' : 'META');

        if (! in_array($method, SiteVerificationService::METHODS[$site['type']], true)) {
            return Response::error("Un dominio intero ({$site['host']}) si verifica solo via DNS (DNS_TXT o DNS_CNAME).");
        }

        if (isset($validated['sitemap_url']) && BingSyncService::host($validated['sitemap_url']) !== BingSyncService::host('https://'.$site['host'])) {
            return Response::error("La sitemap {$validated['sitemap_url']} non è sul sito {$site['host']}.");
        }

        $connection = $this->resolveGoogleConnection($validated['google_account'] ?? null);

        if (is_string($connection)) {
            return Response::error($connection);
        }

        try {
            $this->verification->verify($connection, $site['type'], $site['identifier'], $method);
            $this->searchConsole->addSite($connection, $site['site_url']);
        } catch (SearchConsoleApiException $e) {
            return Response::error($e->getMessage());
        }

        [$property, $linked] = $this->linkProperty($connection->id, $site);
        $result = [
            'site' => $site['site_url'],
            'verified' => true,
            'google_account' => $connection->google_email,
            'pulso_property' => ['id' => $property->id, 'name' => $property->display_name, 'status' => $linked],
            'note' => 'Keep the verification code published: removing it unverifies the site. Search Console data starts from the day of verification, with a 2-3 day delay.',
        ];

        if (isset($validated['sitemap_url'])) {
            try {
                $this->searchConsole->submitSitemap($property, $validated['sitemap_url']);
                $result['sitemap'] = $validated['sitemap_url'];
            } catch (SearchConsoleApiException $e) {
                $result['sitemap_error'] = $e->getMessage();
            }
        }

        return Response::text(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Link the site to the property with the same domain, or create a Search
     * Console only property for it.
     *
     * @param  array{type: string, identifier: string, site_url: string, host: string}  $site
     * @return array{0: GaProperty, 1: string}
     */
    private function linkProperty(int $connectionId, array $site): array
    {
        $user = $this->currentUser();
        $host = BingSyncService::host('https://'.$site['host']);

        $existing = $user->gaProperties()->where('gsc_site_url', $site['site_url'])->first()
            ?? $user->gaProperties()->get()->first(fn (GaProperty $property): bool => BingSyncService::host($property->website_url) === $host);

        if ($existing) {
            if ($existing->gsc_site_url === null) {
                $existing->update(['gsc_site_url' => $site['site_url']]);
            }

            return [$existing, 'linked_to_existing'];
        }

        $property = GaProperty::create([
            'user_id' => $user->id,
            'ga_connection_id' => $connectionId,
            'property_id' => null,
            'gsc_site_url' => $site['site_url'],
            'display_name' => $host,
            'website_url' => SearchConsoleService::websiteUrlFor($site['site_url']),
        ]);

        BackfillSearchConsoleData::dispatch($property);

        return [$property, 'created_search_console_only'];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site' => $schema->string()
                ->description('The same site passed to get-site-verification-token: example.com or https://www.example.com/.')
                ->required(),
            'method' => $schema->string()
                ->enum(['DNS_TXT', 'DNS_CNAME', 'META', 'FILE', 'ANALYTICS', 'TAG_MANAGER'])
                ->description('The verification method used. Defaults to DNS_TXT for a domain and META for a URL.'),
            'google_account' => $schema->string()
                ->description('Email of the Google account connected in Pulso. Needed only when several accounts are connected.'),
            'sitemap_url' => $schema->string()
                ->description('Optional sitemap to submit right after adding the site, e.g. https://example.com/sitemap.xml.'),
        ];
    }
}

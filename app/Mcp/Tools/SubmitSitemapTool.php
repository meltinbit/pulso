<?php

namespace App\Mcp\Tools;

use App\Exceptions\SearchConsoleApiException;
use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Services\BingSyncService;
use App\Services\SearchConsoleService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Submit or resubmit a sitemap to Google Search Console for a property, e.g. for a new site, after adding many pages or after changing the URL structure. Google queues it and downloads it later: check the outcome with list-sitemaps after a while. Submitting does not force indexing, it tells Google where the pages are and that they changed. The sitemap must be on the property\'s site; the Google account needs full Search Console access (reconnect it in Settings → Google if Pulso reports read-only access).')]
#[IsIdempotent]
class SubmitSitemapTool extends Tool
{
    use ResolvesMcpContext;

    public function __construct(
        private SearchConsoleService $searchConsole,
    ) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => 'required|integer',
            'sitemap_url' => 'required|url|max:2048',
        ]);

        $property = $this->resolveAuthorizedProperty($validated['property_id']);
        $sitemapUrl = $validated['sitemap_url'];

        if (BingSyncService::host($sitemapUrl) !== BingSyncService::host($property->website_url)) {
            return Response::error("La sitemap {$sitemapUrl} non è sul sito di {$property->display_name} ({$property->website_url}).");
        }

        try {
            $this->searchConsole->submitSitemap($property, $sitemapUrl);
        } catch (SearchConsoleApiException $e) {
            return Response::error($e->getMessage());
        }

        return Response::text(json_encode([
            'property' => $property->display_name,
            'submitted' => $sitemapUrl,
            'message' => 'Sitemap submitted: Google will download it in the next hours or days. Check last_downloaded and errors with list-sitemaps.',
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'property_id' => $schema->integer()
                ->description('The internal ID of the property. Use list-properties to find it.')
                ->required(),
            'sitemap_url' => $schema->string()
                ->description('Full URL of the sitemap or sitemap index, e.g. https://example.com/sitemap.xml.')
                ->required(),
        ];
    }
}

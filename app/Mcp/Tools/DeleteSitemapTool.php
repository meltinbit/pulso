<?php

namespace App\Mcp\Tools;

use App\Exceptions\SearchConsoleApiException;
use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Services\SearchConsoleService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Remove a sitemap from Google Search Console for a property, e.g. an old sitemap that no longer exists or was replaced. Pages already indexed stay indexed; Google only stops reading that file. Only for sitemaps listed by list-sitemaps; ask the user before removing the main sitemap of a site.')]
#[IsDestructive]
class DeleteSitemapTool extends Tool
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

        try {
            $listed = collect($this->searchConsole->sitemaps($property))->pluck('path');

            if (! $listed->contains($sitemapUrl)) {
                return Response::error("{$sitemapUrl} non è tra le sitemap di {$property->display_name} in Search Console: ".($listed->implode(', ') ?: 'nessuna sitemap inviata').'.');
            }

            $this->searchConsole->deleteSitemap($property, $sitemapUrl);
        } catch (SearchConsoleApiException $e) {
            return Response::error($e->getMessage());
        }

        return Response::text(json_encode([
            'property' => $property->display_name,
            'deleted' => $sitemapUrl,
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
                ->description('Full URL of the sitemap exactly as list-sitemaps returns it.')
                ->required(),
        ];
    }
}

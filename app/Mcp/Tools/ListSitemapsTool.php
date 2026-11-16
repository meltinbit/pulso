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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List the sitemaps submitted to Google Search Console for a property, with their status: last submitted, last downloaded by Google, pending, sitemap index or not, error and warning counts, and URLs submitted per content type. Google exposes only the counts of errors and warnings, not their details: when errors > 0, open the sitemap URL or run check-sitemap-pages to find the broken entries. Use submit-sitemap to add or resubmit one, delete-sitemap to remove one.')]
#[IsReadOnly]
#[IsIdempotent]
class ListSitemapsTool extends Tool
{
    use ResolvesMcpContext;

    public function __construct(
        private SearchConsoleService $searchConsole,
    ) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => 'required|integer',
        ]);

        $property = $this->resolveAuthorizedProperty($validated['property_id']);

        try {
            $site = $this->searchConsole->siteFor($property);
            $sitemaps = $this->searchConsole->sitemaps($property);
        } catch (SearchConsoleApiException $e) {
            return Response::error($e->getMessage());
        }

        $result = [
            'property' => $property->display_name,
            'search_console_site' => $site,
            'sitemaps' => $sitemaps,
        ];

        if ($sitemaps === []) {
            $result['message'] = 'No sitemap submitted in Search Console: submit one with submit-sitemap (usually '.rtrim((string) $property->website_url, '/').'/sitemap.xml).';
        }

        return Response::text(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
        ];
    }
}

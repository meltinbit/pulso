<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Services\SearchConsoleService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Inspect Google index status for property URLs. You can pass explicit URLs, or let the tool discover URLs from the property sitemap. Returns indexed vs non-indexed pages, plus coverage reasons such as Crawled currently not indexed, Excluded by noindex tag, canonicalized URLs, fetch errors, and robots.txt blocks.')]
#[IsReadOnly]
#[IsIdempotent]
class GetPropertyIndexStatusTool extends Tool
{
    use ResolvesMcpContext;

    public function __construct(
        private SearchConsoleService $searchConsole,
    ) {}

    public function handle(Request $request): Response
    {
        $urls = $this->resolveArrayParam($request->get('urls'));

        if ($urls instanceof Response) {
            return $urls;
        }

        $validated = Validator::make([
            'property_id' => $request->get('property_id'),
            'urls' => $urls,
            'sitemap_url' => $request->get('sitemap_url'),
            'limit' => $request->get('limit'),
            'verdict' => $request->get('verdict'),
            'language_code' => $request->get('language_code'),
        ], [
            'property_id' => 'required|integer',
            'urls' => 'nullable|array|min:1|max:50',
            'urls.*' => 'string|url',
            'sitemap_url' => 'nullable|url',
            'limit' => 'nullable|integer|min:1|max:50',
            'verdict' => 'nullable|in:all,indexed,not_indexed',
            'language_code' => 'nullable|string|max:16',
        ])->validate();

        $property = $this->resolveAuthorizedProperty($validated['property_id']);
        $limit = $validated['limit'] ?? 20;
        $verdictFilter = $validated['verdict'] ?? 'all';
        $languageCode = $validated['language_code'] ?? 'en-US';

        $inputUrls = $validated['urls']
            ?? $this->searchConsole->discoverUrlsFromSitemaps($property, $validated['sitemap_url'] ?? null, $limit);

        $inspections = $this->searchConsole->inspectUrls($property, array_slice($inputUrls, 0, $limit), $languageCode);
        $filtered = collect($inspections)->filter(fn (array $inspection): bool => match ($verdictFilter) {
            'indexed' => $inspection['is_indexed'] === true,
            'not_indexed' => $inspection['is_indexed'] === false,
            default => true,
        })->values();

        $result = [
            'property' => $property->display_name,
            'website_url' => $property->website_url,
            'source' => isset($validated['urls']) ? 'provided_urls' : 'sitemap',
            'input_url_count' => count($inputUrls),
            'inspected_count' => count($inspections),
            'returned_count' => $filtered->count(),
            'indexed_count' => $filtered->where('is_indexed', true)->count(),
            'not_indexed_count' => $filtered->where('is_indexed', false)->count(),
            'unknown_count' => $filtered->where('is_indexed', null)->count(),
            'non_indexed_reasons' => $filtered
                ->where('is_indexed', false)
                ->pluck('coverage_state')
                ->filter()
                ->countBy()
                ->sortDesc()
                ->all(),
            'pages' => $filtered->all(),
        ];

        if ($inspections === []) {
            $result['message'] = 'No URLs could be inspected. Verify that the Search Console property matches the website host and that at least one sitemap or URL is available.';
        }

        return Response::text(json_encode($result, JSON_PRETTY_PRINT));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'property_id' => $schema->integer()
                ->description('The internal ID of the GA4 property. Use list-properties to find it.')
                ->required(),
            'urls' => $schema->array()
                ->items($schema->string()->description('Fully qualified URL to inspect, for example https://example.com/blog/post'))
                ->description('Optional explicit URLs to inspect. If omitted, the tool will try to discover URLs from the property sitemap.'),
            'sitemap_url' => $schema->string()
                ->description('Optional sitemap URL to use for discovery instead of the submitted Search Console sitemaps.'),
            'limit' => $schema->integer()
                ->description('Maximum number of URLs to inspect. Defaults to 20, maximum 50.'),
            'verdict' => $schema->string()
                ->enum(['all', 'indexed', 'not_indexed'])
                ->description('Filter results by index outcome. Defaults to all.'),
            'language_code' => $schema->string()
                ->description('Optional BCP-47 language code for translated issue messages. Defaults to en-US.'),
        ];
    }

    /**
     * @return array<int, mixed>|null|Response
     */
    private function resolveArrayParam(mixed $value): array|null|Response
    {
        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return Response::error('Invalid urls parameter: '.json_last_error_msg());
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return Response::error('Invalid urls parameter: expected a JSON array.');
        }

        return $decoded;
    }
}

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

#[Description('Inspect Google index status for property URLs. You can pass explicit URLs, or let the tool discover URLs from the property sitemap. Returns indexed vs non-indexed pages, plus coverage reasons such as Crawled currently not indexed, Excluded by noindex tag, canonicalized URLs, fetch errors, and robots.txt blocks, with what_to_do for each reason, the crawler used (crawled_as) and the rich results detected with their issues.')]
#[IsReadOnly]
#[IsIdempotent]
class GetPropertyIndexStatusTool extends Tool
{
    use ResolvesMcpContext;

    /**
     * What to do for the common coverage states (English, as returned with the
     * default language_code en-US).
     *
     * @var array<string, string>
     */
    private const ADVICE = [
        'Crawled - currently not indexed' => 'Google read the page and chose not to index it: usually thin, duplicate or low value content. Improve and differentiate the content, add internal links from strong pages, then resubmit the sitemap.',
        'Discovered - currently not indexed' => 'Google knows the URL but has not crawled it yet: crawl budget or weak signals. Add internal links from indexed pages, keep it in the sitemap, check server speed.',
        'URL is unknown to Google' => 'Google has never seen the URL: add it to the sitemap and resubmit it with submit-sitemap, and link it from indexed pages.',
        "Excluded by 'noindex' tag" => 'The page has a noindex meta tag or X-Robots-Tag header: remove it if the page should rank, otherwise nothing to do.',
        'Blocked by robots.txt' => 'robots.txt blocks crawling: change the Disallow rule if the page should rank, and remove it from the sitemap otherwise.',
        'Duplicate without user-selected canonical' => 'Google sees the page as a duplicate and there is no canonical: add a rel=canonical to the preferred URL or make the content distinct.',
        'Duplicate, Google chose different canonical than user' => 'Google ignores the declared canonical and picked google_canonical: align canonical, internal links and sitemap on one URL, or differentiate the content.',
        'Alternate page with proper canonical tag' => 'Expected for variants that point to their canonical: nothing to do, keep only canonical URLs in the sitemap.',
        'Page with redirect' => 'The URL redirects: put the final URL in the sitemap and internal links instead of this one.',
        'Not found (404)' => 'The page does not exist: restore it, redirect it (301) to the closest page, or remove it from the sitemap and internal links.',
        'Soft 404' => 'The page answers 200 but looks empty or like an error: add real content or return a proper 404/410, or redirect it.',
        'Server error (5xx)' => 'The server failed when Google crawled: check logs, timeouts and hosting capacity, then resubmit.',
        'Redirect error' => 'Redirect loop, chain too long or redirect to an invalid URL: fix the redirect to point directly to the final page.',
        'Blocked due to unauthorized request (401)' => 'The page requires login: make it public or remove it from the sitemap.',
        'Blocked due to access forbidden (403)' => 'The server forbids Googlebot: check firewall, WAF or bot protection rules.',
        'Blocked due to other 4xx issue' => 'The page returns a 4xx error: check the exact status and fix it or remove the URL from the sitemap.',
    ];

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

        $result['what_to_do'] = collect($result['non_indexed_reasons'])
            ->keys()
            ->mapWithKeys(fn (string $reason): array => [$reason => self::ADVICE[$reason] ?? 'Open inspection_result_link in Search Console for the details of this state.'])
            ->all();

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

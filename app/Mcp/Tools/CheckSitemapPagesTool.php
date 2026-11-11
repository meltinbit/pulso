<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Models\GaProperty;
use App\Models\PropertySearchQuery;
use App\Services\SearchConsoleService;
use App\Services\SitePageChecker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Technical check of the pages in the property sitemap (or of the URLs passed): requests each page like a crawler, without following redirects, and reports not_found (404/410), client_error, server_error, redirect (with target), error (no response), canonical_elsewhere (canonical pointing to another URL, with target), missing_canonical, noindex (meta robots or X-Robots-Tag), slow (over 1.5 s) and no_impressions (a healthy page with no Search Console impressions in the last 90 days: probably not indexed, confirm with get-property-index-status). Use path to check one section, offset to page through large sitemaps. Returns a summary per issue and, by default, only the pages with issues; output is columnar.')]
#[IsReadOnly]
class CheckSitemapPagesTool extends Tool
{
    use ResolvesMcpContext;

    /** URLs read from the sitemaps at most. */
    private const MAX_DISCOVERED = 5000;

    /** Response time above which a page is slow, in milliseconds. */
    private const SLOW_MS = 1500;

    public function __construct(
        private SearchConsoleService $searchConsole,
        private SitePageChecker $checker,
    ) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => 'required|integer',
            'urls' => 'nullable|array|max:300',
            'urls.*' => 'string|max:2048',
            'sitemap_url' => 'nullable|url',
            'path' => 'nullable|string|max:1024',
            'limit' => 'nullable|integer|min:1|max:300',
            'offset' => 'nullable|integer|min:0',
            'only_problems' => 'nullable|boolean',
        ]);

        $property = $this->resolveAuthorizedProperty($validated['property_id']);
        $limit = $validated['limit'] ?? 100;
        $offset = $validated['offset'] ?? 0;
        $path = filled($validated['path'] ?? null) ? trim($validated['path']) : null;

        $all = isset($validated['urls'])
            ? array_map(fn (string $url): string => $this->absolute($url, $property), $validated['urls'])
            : $this->sitemapUrls($property, $validated['sitemap_url'] ?? null);

        $matching = array_values(array_filter(array_unique($all), fn (string $url): bool => $path === null || str_contains(parse_url($url, PHP_URL_PATH) ?: '/', $path)));
        $urls = array_slice($matching, $offset, $limit);

        if ($urls === []) {
            return Response::error('Nessuna URL da controllare: verifica la sitemap della property (o passa sitemap_url) e il filtro path.');
        }

        $impressions = $this->impressions($property, $urls);
        $hasSearchData = PropertySearchQuery::where('ga_property_id', $property->id)->exists();

        $pages = collect($this->checker->check($urls))->map(function (array $page) use ($impressions, $hasSearchData): array {
            $page['impressions'] = $impressions[$page['url']] ?? 0;
            $page['issues'] = $this->issues($page, $hasSearchData);

            return $page;
        });

        $byIssue = $pages->flatMap(fn (array $page): array => $page['issues'])->countBy()->sortDesc();
        $shown = ($validated['only_problems'] ?? true) ? $pages->filter(fn (array $page): bool => $page['issues'] !== []) : $pages;

        return Response::text(json_encode([
            'property' => $property->display_name,
            'source' => isset($validated['urls']) ? 'urls' : 'sitemap',
            'summary' => [
                'urls_found' => count($matching),
                'checked' => count($urls),
                'offset' => $offset,
                'next_offset' => $offset + count($urls) < count($matching) ? $offset + count($urls) : null,
                'ok' => $pages->where('issues', [])->count(),
                'by_issue' => $byIssue,
                'median_ms' => $pages->pluck('ms')->filter()->median(),
            ],
            'cols' => ['url', 'status', 'ms', 'impressions_90d', 'issues', 'target'],
            'rows' => $shown->map(fn (array $page): array => [
                $page['url'], $page['status'], $page['ms'], $page['impressions'], $page['issues'],
                $page['location'] ?? (in_array('canonical_elsewhere', $page['issues'], true) ? $page['canonical'] : null) ?? $page['error'],
            ])->values(),
        ]));
    }

    /**
     * @param  array{url: string, status: ?int, location: ?string, canonical: ?string, noindex: bool, ms: ?int, error: ?string, impressions: int}  $page
     * @return list<string>
     */
    private function issues(array $page, bool $hasSearchData): array
    {
        $status = $page['status'];

        if ($status === null) {
            return ['error'];
        }

        $issues = match (true) {
            in_array($status, [404, 410], true) => ['not_found'],
            $status >= 500 => ['server_error'],
            $status >= 400 => ['client_error'],
            $status >= 300 => ['redirect'],
            default => [],
        };

        if ($status >= 200 && $status < 300) {
            if ($page['canonical'] === null) {
                $issues[] = 'missing_canonical';
            } elseif ($this->normalize($page['canonical']) !== $this->normalize($page['url'])) {
                $issues[] = 'canonical_elsewhere';
            }

            if ($page['noindex']) {
                $issues[] = 'noindex';
            }

            if ($hasSearchData && $page['impressions'] === 0 && ! $page['noindex'] && ! in_array('canonical_elsewhere', $issues, true)) {
                $issues[] = 'no_impressions';
            }
        }

        if (($page['ms'] ?? 0) > self::SLOW_MS) {
            $issues[] = 'slow';
        }

        return $issues;
    }

    /**
     * URLs of the property sitemaps, cached for an hour so paging with
     * offset does not download them again.
     *
     * @return list<string>
     */
    private function sitemapUrls(GaProperty $property, ?string $sitemapUrl): array
    {
        return Cache::remember(
            'sitemap-pages:'.$property->id.':'.sha1((string) $sitemapUrl),
            3600,
            fn (): array => $this->searchConsole->discoverUrlsFromSitemaps($property, $sitemapUrl, self::MAX_DISCOVERED),
        );
    }

    /**
     * Search Console impressions of each URL in the last 90 days.
     *
     * @param  list<string>  $urls
     * @return array<string, int>
     */
    private function impressions(GaProperty $property, array $urls): array
    {
        return PropertySearchQuery::query()
            ->where('ga_property_id', $property->id)
            ->where('date', '>=', now()->subDays(90)->toDateString())
            ->whereIn('page', $urls)
            ->groupBy('page')
            ->selectRaw('page, SUM(impressions) as impressions')
            ->pluck('impressions', 'page')
            ->map(fn ($impressions): int => (int) $impressions)
            ->all();
    }

    private function absolute(string $url, GaProperty $property): string
    {
        if (! str_starts_with($url, '/') || blank($property->website_url)) {
            return $url;
        }

        return rtrim((string) preg_replace('#^(https?://[^/]+).*$#i', '$1', $property->website_url), '/').$url;
    }

    /**
     * Scheme and host in lower case, fragment dropped, the root path always
     * "/": the rest of the URL, trailing slash included, must match exactly.
     */
    private function normalize(string $url): string
    {
        $url = explode('#', $url)[0];

        return (string) preg_replace_callback('#^(https?://[^/?]+)(.*)$#i', fn (array $match): string => strtolower($match[1]).($match[2] === '' || $match[2][0] === '?' ? '/'.$match[2] : $match[2]), $url);
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
                ->items($schema->string())
                ->description('URLs or paths to check instead of the sitemap (max 300).'),
            'sitemap_url' => $schema->string()
                ->description('Sitemap to read instead of the ones submitted to Search Console.'),
            'path' => $schema->string()
                ->description('Only sitemap URLs whose path contains this text, e.g. /calcolo/imu/.'),
            'limit' => $schema->integer()
                ->description('Pages to check. Defaults to 100, maximum 300.'),
            'offset' => $schema->integer()
                ->description('Skip this many matching URLs; use next_offset from the previous call.'),
            'only_problems' => $schema->boolean()
                ->description('Return only pages with issues. Defaults to true; the summary always covers every checked page.'),
        ];
    }
}

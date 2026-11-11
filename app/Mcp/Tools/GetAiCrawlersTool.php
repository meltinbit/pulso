<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Models\AiCrawlerVisit;
use App\Models\GaProperty;
use App\Services\AiCrawlers;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('AI crawler visits to a property, reported by the site itself: GPTBot, OAI-SearchBot, ChatGPT-User, ClaudeBot, Claude-SearchBot, Claude-User, PerplexityBot, Perplexity-User, Meta, Amazon, Common Crawl and others, each with its operator and category (training = model training data, search = index used for answers with sources, user = fetched live while a user asks). Returns per-bot totals, the daily trend by category, the most visited pages with their bots, pages served with errors to AI crawlers, and the pages of recent content annotations that no AI crawler has visited yet. Search and user visits matter most for GEO. Defaults to the last 30 days; user agents are not verified against the vendors\' IP ranges.')]
#[IsReadOnly]
class GetAiCrawlersTool extends Tool
{
    use ResolvesMcpContext;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => 'required|integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'bot' => 'nullable|string|max:40',
            'path' => 'nullable|string|max:1024',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $property = $this->resolveAuthorizedProperty($validated['property_id']);
        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : Carbon::today($property->timezone ?: 'UTC');
        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : $to->copy()->subDays(29);

        if ($property->crawler_token_hash === null) {
            return Response::error("La property {$property->display_name} non è collegata: genera il token crawler in Impostazioni → AI visibility e aggiungi il middleware ReportAiCrawlers al sito.");
        }

        $visits = $property->aiCrawlerVisits()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->when($validated['bot'] ?? null, fn ($query, string $bot) => $query->where('bot', $bot))
            ->when($validated['path'] ?? null, fn ($query, string $path) => $query->where('path', 'like', $this->pathPrefix($path).'%'))
            ->get(['date', 'bot', 'path', 'hits', 'last_status', 'last_seen_at']);

        $limit = $validated['limit'] ?? 30;

        return Response::text(json_encode([
            'property' => $property->display_name,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => [
                'hits' => $visits->sum('hits'),
                'pages' => $visits->pluck('path')->unique()->count(),
                'by_category' => collect(AiCrawlers::CATEGORIES)->mapWithKeys(fn (string $category): array => [
                    $category => $visits->filter(fn (AiCrawlerVisit $visit): bool => $this->category($visit->bot) === $category)->sum('hits'),
                ]),
            ],
            'bots' => [
                'cols' => ['bot', 'operator', 'category', 'hits', 'pages', 'last_seen'],
                'rows' => $visits->groupBy('bot')
                    ->map(fn (Collection $rows, string $bot): array => [
                        $bot,
                        AiCrawlers::BOTS[$bot]['operator'] ?? null,
                        $this->category($bot),
                        $rows->sum('hits'),
                        $rows->pluck('path')->unique()->count(),
                        $rows->max('last_seen_at')?->format('Y-m-d H:i'),
                    ])
                    ->sortByDesc(3)
                    ->values(),
            ],
            'daily' => [
                'cols' => ['date', ...AiCrawlers::CATEGORIES],
                'rows' => $visits->groupBy(fn (AiCrawlerVisit $visit): string => $visit->date->toDateString())
                    ->sortKeys()
                    ->map(fn (Collection $rows, string $date): array => [
                        $date,
                        ...array_map(fn (string $category): int => $rows->filter(fn (AiCrawlerVisit $visit): bool => $this->category($visit->bot) === $category)->sum('hits'), AiCrawlers::CATEGORIES),
                    ])
                    ->values(),
            ],
            'pages' => [
                'cols' => ['path', 'hits', 'bots', 'last_seen'],
                'rows' => $visits->groupBy('path')
                    ->map(fn (Collection $rows, string $path): array => [
                        $path,
                        $rows->sum('hits'),
                        $rows->pluck('bot')->unique()->values()->all(),
                        $rows->max('last_seen_at')?->format('Y-m-d H:i'),
                    ])
                    ->sortByDesc(1)
                    ->take($limit)
                    ->values(),
            ],
            'errors' => $visits->where('last_status', '>=', 400)
                ->groupBy('path')
                ->map(fn (Collection $rows, string $path): array => ['path' => $path, 'status' => $rows->sortByDesc('last_seen_at')->first()->last_status, 'bots' => $rows->pluck('bot')->unique()->values()->all()])
                ->take($limit)
                ->values(),
            'new_content_not_visited' => $this->unvisitedContent($property, $from, $to),
        ]));
    }

    private function category(string $bot): ?string
    {
        return AiCrawlers::BOTS[$bot]['category'] ?? null;
    }

    /**
     * Pages of content annotations in the range that no AI crawler has
     * visited since the day they were published.
     *
     * @return list<array{path: string, published: string}>
     */
    private function unvisitedContent(GaProperty $property, Carbon $from, Carbon $to): array
    {
        $pages = [];

        $property->annotations()
            ->where('type', 'content')
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get(['date', 'urls'])
            ->each(function ($annotation) use (&$pages): void {
                foreach ($annotation->urls ?? [] as $url) {
                    $pages[$this->pathPrefix($url)] = $annotation->date->toDateString();
                }
            });

        $unvisited = [];

        foreach ($pages as $path => $published) {
            $visited = $property->aiCrawlerVisits()
                ->where('path_hash', sha1($path))
                ->whereDate('date', '>=', $published)
                ->exists();

            if (! $visited) {
                $unvisited[] = ['path' => $path, 'published' => $published];
            }
        }

        return $unvisited;
    }

    /**
     * Path part of a URL or path, as the sites report it.
     */
    private function pathPrefix(string $url): string
    {
        return '/'.ltrim(parse_url($url, PHP_URL_PATH) ?: '/', '/');
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
            'from' => $schema->string()
                ->description('Start date (YYYY-MM-DD). Defaults to 30 days before "to".'),
            'to' => $schema->string()
                ->description('End date (YYYY-MM-DD). Defaults to today.'),
            'bot' => $schema->string()
                ->description('Only this crawler, e.g. OAI-SearchBot.'),
            'path' => $schema->string()
                ->description('Only pages under this path or URL, e.g. /blog/.'),
            'limit' => $schema->integer()
                ->description('Rows of pages and errors to return. Defaults to 30, maximum 200.'),
        ];
    }
}

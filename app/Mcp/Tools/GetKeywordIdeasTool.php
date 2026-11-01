<?php

namespace App\Mcp\Tools;

use App\Exceptions\GoogleAdsApiException;
use App\Mcp\Tools\Concerns\FormatsKeywordResearch;
use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Services\GoogleAdsKeywordService;
use App\Services\KeywordGapService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Generate keyword ideas with Google Ads Keyword Planner (Google Search network) from seed keywords and/or a URL. Returns average monthly search volume, 12-month trend, competition and top of page CPC range, sorted by volume. Pass property_id to also get the Search Console position and impressions of that property for each idea (last 90 days). Defaults: Italian language, Italy. Results are cached for 30 days.')]
#[IsReadOnly]
#[IsIdempotent]
class GetKeywordIdeasTool extends Tool
{
    use FormatsKeywordResearch;
    use ResolvesMcpContext;

    public function __construct(
        private GoogleAdsKeywordService $keywords,
        private KeywordGapService $gap,
    ) {}

    public function handle(Request $request): Response
    {
        $validated = Validator::make([
            'property_id' => $request->get('property_id'),
            'seeds' => $this->listParam($request->get('seeds')),
            'url' => $request->get('url'),
            'location' => $request->get('location'),
            'language' => $request->get('language'),
            'limit' => $request->get('limit'),
        ], [
            'property_id' => 'nullable|integer',
            'seeds' => 'array|max:'.GoogleAdsKeywordService::MAX_SEEDS.'|required_without:url',
            'seeds.*' => 'string|max:80',
            'url' => 'nullable|url',
            'location' => 'nullable|string|max:120',
            'language' => 'nullable|string|max:20',
            'limit' => 'nullable|integer|min:1|max:200',
        ])->validate();

        $property = isset($validated['property_id']) ? $this->resolveAuthorizedProperty($validated['property_id']) : null;

        try {
            $geo = $this->keywords->resolveLocations($this->listParam($validated['location'] ?? ''));
            $ideas = $this->keywords->keywordIdeas(
                $validated['seeds'] ?? [],
                $validated['url'] ?? null,
                $geo,
                $validated['language'] ?? null,
                $validated['limit'] ?? 50,
            );
        } catch (GoogleAdsApiException $e) {
            return Response::error($e->getMessage());
        }

        usort($ideas, fn (array $a, array $b): int => ($b['volume'] ?? -1) <=> ($a['volume'] ?? -1));

        $columns = $this->keywordColumns();

        if ($property) {
            $ideas = $this->gap->annotate($property, $ideas);
            $columns = [...$columns, 'gsc_pos', 'gsc_impr', 'gsc_clicks'];
        }

        return $this->compactResponse(array_filter([
            'property' => $property?->display_name,
            'geo' => $geo,
            'legend' => $this->legend().($property ? '; gsc_*=Search Console last 90 days, null = site not present' : ''),
            'cols' => $columns,
            'rows' => array_map(
                fn (array $idea): array => $property ? [...$this->keywordRow($idea), ...$this->gscCells($idea['gsc'])] : $this->keywordRow($idea),
                $ideas,
            ),
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'seeds' => $schema->array()
                ->items($schema->string())
                ->description('Seed keywords (max 20). Required unless url is given.'),
            'url' => $schema->string()
                ->description('Optional page or site URL to generate ideas from, alone or together with seeds.'),
            'property_id' => $schema->integer()
                ->description('Optional internal ID of the GA4 property, to add its Search Console position per keyword. Use list-properties to find it.'),
            'location' => $schema->string()
                ->description('Geo target ID or place name (e.g. "2380" or "Ferrara"). Comma separate for several. Defaults to Italy. Use search-geo-targets to pick an exact ID, e.g. a province vs a city.'),
            'language' => $schema->string()
                ->description('Language code (it, en, de, fr, es) or languageConstant ID. Defaults to it.'),
            'limit' => $schema->integer()
                ->description('Maximum ideas to return. Defaults to 50, maximum 200.'),
        ];
    }
}

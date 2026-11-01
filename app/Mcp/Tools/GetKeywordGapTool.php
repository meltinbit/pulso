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

#[Description('Keyword gap for a property: generates Google Ads keyword ideas from seeds and marks for each one whether the site already appears in Search Console for that exact query (last 90 days, accent and case insensitive), with average position and impressions. Returns a summary (present, missing, monthly search volume the site is missing) and rows sorted by volume. Use only_gap to list just the keywords where the site does not appear.')]
#[IsReadOnly]
#[IsIdempotent]
class GetKeywordGapTool extends Tool
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
            'location' => $request->get('location'),
            'language' => $request->get('language'),
            'limit' => $request->get('limit'),
            'only_gap' => $request->get('only_gap'),
        ], [
            'property_id' => 'required|integer',
            'seeds' => 'required|array|min:1|max:'.GoogleAdsKeywordService::MAX_SEEDS,
            'seeds.*' => 'string|max:80',
            'location' => 'nullable|string|max:120',
            'language' => 'nullable|string|max:20',
            'limit' => 'nullable|integer|min:1|max:200',
            'only_gap' => 'nullable|boolean',
        ])->validate();

        $property = $this->resolveAuthorizedProperty($validated['property_id']);

        try {
            $geo = $this->keywords->resolveLocations($this->listParam($validated['location'] ?? ''));
            $ideas = $this->keywords->keywordIdeas($validated['seeds'], null, $geo, $validated['language'] ?? null, $validated['limit'] ?? 100);
        } catch (GoogleAdsApiException $e) {
            return Response::error($e->getMessage());
        }

        $annotated = $this->gap->annotate($property, $ideas);
        usort($annotated, fn (array $a, array $b): int => ($b['volume'] ?? -1) <=> ($a['volume'] ?? -1));

        $rows = ($validated['only_gap'] ?? false)
            ? array_values(array_filter($annotated, fn (array $row): bool => $row['gsc'] === null))
            : $annotated;

        return $this->compactResponse([
            'property' => $property->display_name,
            'geo' => $geo,
            'summary' => $this->gap->summarize($annotated),
            'legend' => $this->legend().'; gsc_*=Search Console last 90 days, null = site not present',
            'cols' => [...$this->keywordColumns(), 'gsc_pos', 'gsc_impr', 'gsc_clicks'],
            'rows' => array_map(fn (array $row): array => [...$this->keywordRow($row), ...$this->gscCells($row['gsc'])], $rows),
        ]);
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
            'seeds' => $schema->array()
                ->items($schema->string())
                ->description('Seed keywords (max 20).')
                ->required(),
            'location' => $schema->string()
                ->description('Geo target ID or place name (e.g. "2380" or "Ferrara"). Defaults to Italy.'),
            'language' => $schema->string()
                ->description('Language code (it, en, de, fr, es) or languageConstant ID. Defaults to it.'),
            'limit' => $schema->integer()
                ->description('Maximum ideas to analyse. Defaults to 100, maximum 200.'),
            'only_gap' => $schema->boolean()
                ->description('Return only keywords where the site does not appear in Search Console. The summary always covers all ideas.'),
        ];
    }
}

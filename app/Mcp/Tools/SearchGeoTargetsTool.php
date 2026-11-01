<?php

namespace App\Mcp\Tools;

use App\Exceptions\GoogleAdsApiException;
use App\Mcp\Tools\Concerns\FormatsKeywordResearch;
use App\Services\GoogleAdsKeywordService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Find Google Ads geo target IDs (country, region, province, city…) by place name, to use as location in the keyword tools. For example "Ferrara" returns both the city and the province with different IDs. Restricted to Italy unless country_code is given.')]
#[IsReadOnly]
#[IsIdempotent]
class SearchGeoTargetsTool extends Tool
{
    use FormatsKeywordResearch;

    public function __construct(
        private GoogleAdsKeywordService $keywords,
    ) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'query' => 'required|string|min:2|max:120',
            'country_code' => 'nullable|string|size:2',
        ]);

        try {
            $targets = $this->keywords->searchGeoTargets($validated['query'], strtoupper($validated['country_code'] ?? 'IT'));
        } catch (GoogleAdsApiException $e) {
            return Response::error($e->getMessage());
        }

        return $this->compactResponse([
            'cols' => ['id', 'name', 'type', 'path'],
            'rows' => array_map(fn (array $target): array => [
                $target['id'],
                $target['name'],
                $target['type'],
                $target['canonical_name'],
            ], $targets),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Place name to search, e.g. "Ferrara" or "Emilia-Romagna".')
                ->required(),
            'country_code' => $schema->string()
                ->description('ISO 3166-1 alpha-2 country code to restrict results. Defaults to IT.'),
        ];
    }
}

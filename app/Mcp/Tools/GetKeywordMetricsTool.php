<?php

namespace App\Mcp\Tools;

use App\Exceptions\GoogleAdsApiException;
use App\Mcp\Tools\Concerns\FormatsKeywordResearch;
use App\Services\GoogleAdsKeywordService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Get Google Ads historical metrics for specific keywords: average monthly searches, the last 12 monthly volumes, competition and top of page CPC range. Google merges close variants (e.g. singular/plural) into one row. Defaults: Italian language, Italy. Results are cached for 30 days.')]
#[IsReadOnly]
#[IsIdempotent]
class GetKeywordMetricsTool extends Tool
{
    use FormatsKeywordResearch;

    public function __construct(
        private GoogleAdsKeywordService $keywords,
    ) {}

    public function handle(Request $request): Response
    {
        $validated = Validator::make([
            'keywords' => $this->listParam($request->get('keywords')),
            'location' => $request->get('location'),
            'language' => $request->get('language'),
        ], [
            'keywords' => 'required|array|min:1|max:200',
            'keywords.*' => 'string|max:80',
            'location' => 'nullable|string|max:120',
            'language' => 'nullable|string|max:20',
        ])->validate();

        try {
            $geo = $this->keywords->resolveLocations($this->listParam($validated['location'] ?? ''));
            $metrics = $this->keywords->keywordMetrics($validated['keywords'], $geo, $validated['language'] ?? null);
        } catch (GoogleAdsApiException $e) {
            return Response::error($e->getMessage());
        }

        $months = collect($metrics)->pluck('monthly')->flatten(1)->pluck('month')->unique()->sort()->values()->all();

        return $this->compactResponse([
            'geo' => $geo,
            'legend' => $this->legend().'; monthly=searches per month aligned with months',
            'months' => $months,
            'cols' => [...$this->keywordColumns(), 'monthly'],
            'rows' => array_map(fn (array $row): array => [
                ...$this->keywordRow($row),
                array_map(
                    fn (string $month): ?int => collect($row['monthly'])->firstWhere('month', $month)['searches'] ?? null,
                    $months,
                ),
            ], $metrics),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'keywords' => $schema->array()
                ->items($schema->string())
                ->description('Keywords to check (max 200).')
                ->required(),
            'location' => $schema->string()
                ->description('Geo target ID or place name (e.g. "2380" or "Ferrara"). Comma separate for several. Defaults to Italy.'),
            'language' => $schema->string()
                ->description('Language code (it, en, de, fr, es) or languageConstant ID. Defaults to it.'),
        ];
    }
}

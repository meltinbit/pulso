<?php

namespace App\Http\Controllers;

use App\Exceptions\GoogleAdsApiException;
use App\Http\Controllers\Concerns\HasActiveProperty;
use App\Http\Requests\KeywordSearchRequest;
use App\Services\GoogleAdsClient;
use App\Services\GoogleAdsKeywordService;
use App\Services\KeywordGapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KeywordController extends Controller
{
    use HasActiveProperty;

    public function __construct(
        private GoogleAdsKeywordService $keywords,
        private KeywordGapService $gap,
        private GoogleAdsClient $client,
    ) {}

    public function index(KeywordSearchRequest $request): Response
    {
        $property = $this->getActiveProperty($request);
        $locations = $request->locations();
        $results = null;
        $error = null;

        if ($request->hasSearch() && $this->client->isConfigured()) {
            try {
                $ideas = $this->keywords->keywordIdeas(
                    $request->seeds(),
                    $request->validated('url'),
                    array_column($locations, 'id'),
                    $request->validated('language'),
                    (int) ($request->validated('limit') ?? 200),
                );

                $results = $property ? $this->gap->annotate($property, $ideas) : $ideas;
            } catch (GoogleAdsApiException $e) {
                $error = $e->getMessage();
            }
        }

        return Inertia::render('keywords/index', [
            'property' => $property?->only('id', 'display_name', 'website_url'),
            'configured' => $this->client->isConfigured(),
            'usage' => ['today' => $this->client->operationsToday(), 'limit' => $this->client->dailyLimit()],
            'filters' => [
                'seeds' => (string) $request->validated('seeds', ''),
                'url' => (string) $request->validated('url', ''),
                'location' => $locations,
                'language' => (string) $request->validated('language', 'it'),
                'limit' => (int) ($request->validated('limit') ?? 200),
            ],
            'languages' => array_keys(config('services.google_ads.languages', [])),
            'results' => $results,
            'summary' => $results !== null && $property ? $this->gap->summarize($results) : null,
            'gapDays' => KeywordGapService::DAYS,
            'error' => $error,
        ]);
    }

    public function geoTargets(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:120']]);

        try {
            return response()->json(['targets' => $this->keywords->searchGeoTargets($validated['q'])]);
        } catch (GoogleAdsApiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}

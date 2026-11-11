<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Models\AiVisibilityCheck;
use App\Models\AiVisibilityPrompt;
use App\Services\AiVisibilityService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('GEO visibility of a property in ChatGPT answers (OpenAI API with web search), from the tracked prompts (see set-ai-visibility-prompts). Returns the trend per run (prompts checked, how many cite the site, share %, average position among the cited sites), each prompt in the latest run compared with the previous one (gained, lost, kept, still missing, new) with the cited page, and the competitor domains ChatGPT cites most. Answers via API are a proxy of what ChatGPT users see, good for trends. Pass prompt_id to read the latest answers to one prompt.')]
#[IsReadOnly]
class GetAiVisibilityTool extends Tool
{
    use ResolvesMcpContext;

    public function __construct(private AiVisibilityService $aiVisibility) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => 'required|integer',
            'runs' => 'nullable|integer|min:1|max:26',
            'prompt_id' => 'nullable|integer',
        ]);

        $property = $this->resolveAuthorizedProperty($validated['property_id']);

        if (isset($validated['prompt_id'])) {
            $prompt = $property->aiVisibilityPrompts()->findOrFail($validated['prompt_id']);

            return Response::text(json_encode([
                'prompt' => $prompt->prompt,
                'answers' => $prompt->checks()->latest('run_date')->limit(3)->get()->map(fn (AiVisibilityCheck $check): array => [
                    'date' => $check->run_date->toDateString(),
                    'mentioned' => $check->mentioned,
                    'position' => $check->position,
                    'cited_url' => $check->cited_url,
                    'cited_domains' => $check->cited_domains,
                    'answer_excerpt' => $check->answer_excerpt,
                ]),
            ]));
        }

        $runDates = $property->aiVisibilityChecks()
            ->select('run_date')->distinct()->orderByDesc('run_date')
            ->limit($validated['runs'] ?? 8)
            ->pluck('run_date')
            ->map(fn ($date): string => substr((string) $date, 0, 10));

        $checks = $property->aiVisibilityChecks()
            ->when($runDates->isNotEmpty(), fn ($query) => $query->whereDate('run_date', '>=', $runDates->last()))
            ->get(['ai_visibility_prompt_id', 'run_date', 'mentioned', 'position', 'cited_url', 'cited_domains'])
            ->groupBy(fn (AiVisibilityCheck $check): string => $check->run_date->toDateString());

        $latestDate = $runDates->first();
        $previousDate = $runDates->get(1);
        $latest = ($checks[$latestDate] ?? collect())->keyBy('ai_visibility_prompt_id');
        $previous = ($checks[$previousDate] ?? collect())->keyBy('ai_visibility_prompt_id');
        $host = parse_url((string) $property->website_url, PHP_URL_HOST);

        $prompts = $property->aiVisibilityPrompts()->where('is_active', true)->orderBy('id')->get();

        return Response::text(json_encode([
            'property' => $property->display_name,
            'engine' => 'chatgpt (openai api, web search)',
            'brand_terms' => $property->ai_brand_terms ?? [],
            'latest_run' => $latestDate,
            'trend' => [
                'cols' => ['run_date', 'prompts', 'mentioned', 'share_pct', 'avg_position'],
                'rows' => $runDates->reverse()->map(function (string $date) use ($checks): array {
                    $run = $checks[$date] ?? collect();
                    $positions = $run->whereNotNull('position')->pluck('position');

                    return [
                        $date,
                        $run->count(),
                        $run->where('mentioned', true)->count(),
                        $run->count() > 0 ? round($run->where('mentioned', true)->count() / $run->count() * 100, 1) : null,
                        $positions->isEmpty() ? null : round($positions->avg(), 1),
                    ];
                })->values(),
            ],
            'prompts' => [
                'cols' => ['id', 'prompt', 'change', 'mentioned', 'position', 'cited_url'],
                'rows' => $prompts->map(function (AiVisibilityPrompt $prompt) use ($latest, $previous): array {
                    $now = $latest[$prompt->id] ?? null;
                    $before = $previous[$prompt->id] ?? null;

                    return [
                        $prompt->id,
                        $prompt->prompt,
                        $this->change($now, $before),
                        $now?->mentioned,
                        $now?->position,
                        $now?->cited_url,
                    ];
                })->values(),
            ],
            'competitors_latest_run' => $this->competitors($latest->values(), $host),
            'budget' => [
                'spent_this_month_usd' => $this->aiVisibility->spentThisMonthUsd($this->currentUser()),
                'cap_eur' => $this->aiVisibility->monthlyCapEur($this->currentUser()),
            ],
        ]));
    }

    private function change(?AiVisibilityCheck $now, ?AiVisibilityCheck $before): string
    {
        return match (true) {
            $now === null => 'not_checked',
            $before === null => 'new',
            $now->mentioned && ! $before->mentioned => 'gained',
            ! $now->mentioned && $before->mentioned => 'lost',
            $now->mentioned => 'kept',
            default => 'still_missing',
        };
    }

    /**
     * Domains cited most often in the run, the property excluded.
     *
     * @param  Collection<int, AiVisibilityCheck>  $checks
     * @return array<string, int>
     */
    private function competitors(Collection $checks, ?string $host): array
    {
        $host = $host ? preg_replace('/^www\./', '', strtolower($host)) : null;

        return $checks
            ->flatMap(fn (AiVisibilityCheck $check): array => $check->cited_domains ?? [])
            ->reject(fn (string $domain): bool => $host !== null && ($domain === $host || str_ends_with($domain, '.'.$host)))
            ->countBy()
            ->sortDesc()
            ->take(15)
            ->all();
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
            'runs' => $schema->integer()
                ->description('How many past runs to include in the trend. Defaults to 8 (two months with weekly runs).'),
            'prompt_id' => $schema->integer()
                ->description('Return the last 3 answers to this prompt (excerpt, cited domains) instead of the overview.'),
        ];
    }
}

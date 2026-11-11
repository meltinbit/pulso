<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Models\AiVisibilityPrompt;
use App\Services\AiVisibilityService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Manage the GEO prompts tracked for a property: the questions a real user could ask ChatGPT where the site deserves to be cited (e.g. "best calculator for the Italian flat-rate tax"). Pulso asks them to ChatGPT with web search on the configured schedule (weekly by default) and records whether the answer cites the site; read the results with get-ai-visibility. add adds prompts (existing ones are reactivated, not duplicated), remove deactivates prompts by ID while keeping their history, brand_terms replaces the names that count as a mention in the answer text besides the domain. Every check costs money and the monthly budget is capped: keep about 10-20 focused prompts per site, written as a user would ask them, in the language of the site. Returns the active prompts and the expected monthly cost against the cap.')]
#[IsIdempotent]
class SetAiVisibilityPromptsTool extends Tool
{
    use ResolvesMcpContext;

    public function __construct(private AiVisibilityService $aiVisibility) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => 'required|integer',
            'add' => 'nullable|array|max:30',
            'add.*' => 'string|min:5|max:500',
            'remove' => 'nullable|array|max:100',
            'remove.*' => 'integer',
            'brand_terms' => 'nullable|array|max:10',
            'brand_terms.*' => 'string|min:2|max:100',
        ]);

        $property = $this->resolveAuthorizedProperty($validated['property_id']);
        $user = $this->currentUser();

        if (isset($validated['brand_terms'])) {
            $property->update(['ai_brand_terms' => array_values(array_unique(array_map('trim', $validated['brand_terms']))) ?: null]);
        }

        if (! empty($validated['remove'])) {
            $property->aiVisibilityPrompts()->whereKey($validated['remove'])->update(['is_active' => false]);
        }

        $added = 0;

        foreach (array_unique(array_map(fn (string $prompt): string => trim(preg_replace('/\s+/', ' ', $prompt)), $validated['add'] ?? [])) as $text) {
            $active = $property->aiVisibilityPrompts()->where('is_active', true)->count();
            $prompt = $property->aiVisibilityPrompts()->firstOrNew(['prompt' => $text]);

            if ($prompt->exists && $prompt->is_active) {
                continue;
            }

            if ($active >= AiVisibilityService::MAX_ACTIVE_PROMPTS_PER_PROPERTY) {
                return Response::error('Massimo '.AiVisibilityService::MAX_ACTIVE_PROMPTS_PER_PROPERTY.' prompt attivi per sito: rimuovine qualcuno prima di aggiungerne altri.');
            }

            $prompt->fill(['is_active' => true, 'agent' => $this->currentAgent()])->save();
            $added++;
        }

        $prompts = $property->aiVisibilityPrompts()->where('is_active', true)->orderBy('id')->get(['id', 'prompt']);

        return Response::text(json_encode([
            'property' => $property->display_name,
            'added' => $added,
            'brand_terms' => $property->fresh()->ai_brand_terms ?? [],
            'active_prompts' => $prompts->map(fn (AiVisibilityPrompt $prompt): array => [$prompt->id, $prompt->prompt])->values(),
            'budget' => [
                'frequency' => $this->aiVisibility->frequency($user),
                'expected_month_usd_all_sites' => $this->aiVisibility->estimatedMonthlyCostUsd($user),
                'cap_eur' => $this->aiVisibility->monthlyCapEur($user),
                'spent_this_month_usd' => $this->aiVisibility->spentThisMonthUsd($user),
            ],
        ]));
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
            'add' => $schema->array()->items($schema->string())
                ->description('Prompts to track, as a user would type them to ChatGPT.'),
            'remove' => $schema->array()->items($schema->integer())
                ->description('IDs of prompts to stop tracking (from active_prompts or get-ai-visibility).'),
            'brand_terms' => $schema->array()->items($schema->string())
                ->description('Brand or product names that count as a mention when they appear in the answer text (the domain always counts). Replaces the current list; pass [] to clear it.'),
        ];
    }
}

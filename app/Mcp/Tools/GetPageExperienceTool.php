<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Services\PageExperienceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;

#[Description('Core Web Vitals of real Chrome users from the Chrome UX Report: p75 of LCP (ms), INP (ms), CLS, FCP (ms) and TTFB (ms) over the last 28 days, each rated good / needs_improvement / poor, and whether the Core Web Vitals assessment passes (LCP, INP and CLS all good). Defaults to the whole site (origin) on phones; pass url for one page (when CrUX has too little traffic for it, the origin is returned and the answer says so). history adds the weekly p75 trend of LCP, INP and CLS; lighthouse adds a PageSpeed Insights lab audit of the page (performance score, lab metrics and the failing audits with the largest estimated savings), which takes 10-30 seconds.')]
#[IsReadOnly]
class GetPageExperienceTool extends Tool
{
    use ResolvesMcpContext;

    public function __construct(private PageExperienceService $experience) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => 'required|integer',
            'url' => 'nullable|string|max:2048',
            'form_factor' => 'nullable|in:phone,desktop,all',
            'history' => 'nullable|boolean',
            'weeks' => 'nullable|integer|min:2|max:40',
            'lighthouse' => 'nullable|boolean',
        ]);

        $property = $this->resolveAuthorizedProperty($validated['property_id']);
        $apiKey = $this->experience->apiKey($this->currentUser());

        if ($apiKey === null) {
            return Response::error('Manca la API key Google: creala nel progetto Google Cloud di Pulso con Chrome UX Report API e PageSpeed Insights API abilitate, poi salvala in Impostazioni → Google.');
        }

        $origin = preg_replace('#^(https?://[^/]+).*$#i', '$1', (string) $property->website_url);

        if (! preg_match('#^https?://#i', $origin)) {
            return Response::error("La property {$property->display_name} non ha un sito web impostato.");
        }

        $url = filled($validated['url'] ?? null) ? trim($validated['url']) : null;
        $url = $url !== null && str_starts_with($url, '/') ? $origin.$url : $url;
        $formFactor = match ($validated['form_factor'] ?? 'phone') {
            'desktop' => 'DESKTOP',
            'all' => null,
            default => 'PHONE',
        };

        try {
            $target = $url !== null ? ['url' => $url] : ['origin' => $origin];
            $record = $this->experience->record($apiKey, $target, $formFactor);
            $note = null;

            if ($record === null && $url !== null) {
                $target = ['origin' => $origin];
                $record = $this->experience->record($apiKey, $target, $formFactor);
                $note = 'CrUX non ha abbastanza traffico per questa pagina: i dati sono dell\'intero sito.';
            }

            $result = [
                'property' => $property->display_name,
                'scope' => isset($target['url']) ? 'url' : 'origin',
                'target' => $target['url'] ?? $target['origin'],
                'form_factor' => strtolower($formFactor ?? 'all'),
                'note' => $note,
                'crux' => $record === null ? null : $this->summarize($record),
            ];

            if ($record === null) {
                $result['note'] = 'CrUX non ha dati per questo sito: servono abbastanza visite da Chrome negli ultimi 28 giorni.';
            }

            if (($validated['history'] ?? false) && $record !== null) {
                $history = $this->experience->history($apiKey, $target, $formFactor, $validated['weeks'] ?? 12);
                $result['history'] = $history === null ? null : [
                    'cols' => ['period_end', ...array_keys($history['metrics'])],
                    'rows' => collect($history['periods'])->map(fn (string $period, int $index): array => [
                        $period, ...array_map(fn (array $values): ?float => $values[$index] ?? null, array_values($history['metrics'])),
                    ])->all(),
                ];
            }

            if ($validated['lighthouse'] ?? false) {
                $result['lighthouse'] = $this->experience->lighthouse($apiKey, $url ?? $origin.'/', ($validated['form_factor'] ?? 'phone') === 'desktop' ? 'desktop' : 'mobile');
            }
        } catch (RuntimeException $e) {
            return Response::error($e->getMessage());
        }

        return Response::text(json_encode(array_filter($result, fn ($value): bool => $value !== null)));
    }

    /**
     * @param  array{period: array{from: string, to: string}, metrics: array<string, float>}  $record
     * @return array<string, mixed>
     */
    private function summarize(array $record): array
    {
        $metrics = collect($record['metrics'])->map(fn (float $value, string $metric): array => [
            'p75' => $value,
            'rating' => PageExperienceService::rating($metric, $value),
        ]);

        $vitals = $metrics->only(PageExperienceService::CORE_WEB_VITALS);

        return [
            'period' => $record['period'],
            'core_web_vitals' => $vitals->count() < 3 ? 'insufficient_data' : ($vitals->every(fn (array $metric): bool => $metric['rating'] === 'good') ? 'passed' : 'failed'),
            'metrics' => $metrics->all(),
        ];
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
            'url' => $schema->string()
                ->description('One page (full URL or path). Omit for the whole site.'),
            'form_factor' => $schema->string()
                ->enum(['phone', 'desktop', 'all'])
                ->description('Device. Defaults to phone, where Google measures most sites.'),
            'history' => $schema->boolean()
                ->description('Add the weekly p75 trend of LCP, INP and CLS.'),
            'weeks' => $schema->integer()
                ->description('Weeks of history. Defaults to 12, maximum 40.'),
            'lighthouse' => $schema->boolean()
                ->description('Add a PageSpeed Insights lab audit of the page (or of the home page). Slower: 10-30 seconds.'),
        ];
    }
}

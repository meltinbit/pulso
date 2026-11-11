<?php

namespace App\Mcp\Tools;

use App\Exceptions\GaApiException;
use App\Exceptions\GaPermissionException;
use App\Exceptions\GaQuotaExceededException;
use App\Exceptions\GoogleTokenExpiredException;
use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Services\GaClientService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Run a GA4 funnel report for a property: give 2 to 10 ordered steps, each a GA4 event (e.g. session_start, calcolo_eseguito) optionally restricted to pages whose URL contains a text (page_contains, e.g. "/forfettario"; with page_contains alone the event is page_view). Returns, for each step, active users, completion rate to the next step, abandonments and abandonment rate (%), plus the overall conversion from the first to the last step. Closed funnel by default (users must enter at step 1); set open to let users enter at any step. Data comes live from the GA4 Data API, defaults to the last 30 days.')]
#[IsReadOnly]
#[IsIdempotent]
class GetPropertyFunnelTool extends Tool
{
    use ResolvesMcpContext;

    public function __construct(private GaClientService $ga) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => 'required|integer',
            'steps' => 'required|array|min:2|max:10',
            'steps.*.event_name' => 'nullable|string|max:255|required_without:steps.*.page_contains',
            'steps.*.page_contains' => 'nullable|string|max:255',
            'steps.*.name' => 'nullable|string|max:100',
            'open' => 'nullable|boolean',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $property = $this->resolveAuthorizedProperty($validated['property_id']);
        $from = $validated['from'] ?? now()->subDays(30)->toDateString();
        $to = $validated['to'] ?? now()->toDateString();

        try {
            $result = $this->ga->runFunnelReport($property, [
                'dateRanges' => [['startDate' => $from, 'endDate' => $to]],
                'funnel' => [
                    'isOpenFunnel' => (bool) ($validated['open'] ?? false),
                    'steps' => array_map($this->stepDefinition(...), $validated['steps']),
                ],
            ]);
        } catch (GoogleTokenExpiredException|GaPermissionException|GaQuotaExceededException $e) {
            return Response::error($e->getMessage().' Controlla la connessione Google nel pannello di Pulso (Impostazioni → Google).');
        } catch (GaApiException $e) {
            return Response::error('Errore dalla GA4 Data API: '.Str::limit($e->getMessage(), 300));
        }

        $steps = $this->parseFunnelTable($result);
        $firstUsers = $steps[0]['users'] ?? 0;
        $lastUsers = $steps === [] ? 0 : $steps[array_key_last($steps)]['users'];

        return Response::text(json_encode([
            'property' => $property->display_name,
            'from' => $from,
            'to' => $to,
            'open' => (bool) ($validated['open'] ?? false),
            'overall_conversion' => $firstUsers > 0 ? round($lastUsers / $firstUsers * 100, 1) : null,
            'steps' => $steps,
        ]));
    }

    /**
     * @param  array{event_name?: ?string, page_contains?: ?string, name?: ?string}  $step
     * @return array<string, mixed>
     */
    private function stepDefinition(array $step): array
    {
        $eventName = $step['event_name'] ?? null ?: 'page_view';
        $pageContains = $step['page_contains'] ?? null;

        $eventFilter = ['eventName' => $eventName];

        if ($pageContains !== null && $pageContains !== '') {
            $eventFilter['funnelParameterFilterExpression'] = [
                'funnelParameterFilter' => [
                    'eventParameterName' => 'page_location',
                    'stringFilter' => ['matchType' => 'CONTAINS', 'value' => $pageContains, 'caseSensitive' => false],
                ],
            ];
        }

        return [
            'name' => $step['name'] ?? null ?: ($pageContains ? "{$eventName} {$pageContains}" : $eventName),
            'filterExpression' => ['funnelEventFilter' => $eventFilter],
        ];
    }

    /**
     * Read the funnel table, whose metrics are active users, completion rate,
     * abandonments and abandonment rate. Headers are matched by name when
     * present, falling back to that documented order.
     *
     * @param  array<string, mixed>  $result
     * @return list<array{step: string, users: int, completion_rate: float, abandonments: int, abandonment_rate: float}>
     */
    private function parseFunnelTable(array $result): array
    {
        $table = $result['funnelTable'] ?? [];
        $headers = array_map(fn (array $h): string => $h['name'] ?? '', $table['metricHeaders'] ?? []);

        $index = fn (string $name, int $fallback): int => ($found = array_search($name, $headers, true)) === false ? $fallback : $found;
        $users = $index('activeUsers', 0);
        $completion = $index('funnelStepCompletionRate', 1);
        $abandonments = $index('funnelStepAbandonments', 2);
        $abandonmentRate = $index('funnelStepAbandonmentRate', 3);

        $steps = [];

        foreach ($table['rows'] ?? [] as $row) {
            $metrics = $row['metricValues'] ?? [];

            $steps[] = [
                'step' => (string) ($row['dimensionValues'][0]['value'] ?? ''),
                'users' => (int) ($metrics[$users]['value'] ?? 0),
                'completion_rate' => round((float) ($metrics[$completion]['value'] ?? 0) * 100, 1),
                'abandonments' => (int) ($metrics[$abandonments]['value'] ?? 0),
                'abandonment_rate' => round((float) ($metrics[$abandonmentRate]['value'] ?? 0) * 100, 1),
            ];
        }

        return $steps;
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
            'steps' => $schema->array()
                ->items($schema->object([
                    'event_name' => $schema->string()->description('GA4 event name. Defaults to page_view when only page_contains is given.'),
                    'page_contains' => $schema->string()->description('Only count the event on pages whose URL contains this text (case insensitive).'),
                    'name' => $schema->string()->description('Optional label for the step.'),
                ]))
                ->description('Ordered funnel steps, 2 to 10.')
                ->required(),
            'open' => $schema->boolean()
                ->description('Open funnel: users can enter at any step. Defaults to false (closed).'),
            'from' => $schema->string()
                ->description('Start date (YYYY-MM-DD). Defaults to 30 days ago.'),
            'to' => $schema->string()
                ->description('End date (YYYY-MM-DD). Defaults to today.'),
        ];
    }
}

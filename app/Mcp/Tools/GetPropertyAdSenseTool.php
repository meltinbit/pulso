<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Services\AdSenseReportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Get Google AdSense revenue for a GA4 property within a date range, filtered to the property\'s own domain. Returns range totals (estimated earnings, page views, impressions, clicks, ad requests, matched ad requests) with derived page RPM, impression RPM, page CTR (%), CPC and coverage (%) computed from the summed totals, the same metrics for the previous period of equal length with % changes, the per-day series, and breakdowns by country, platform (desktop/mobile/tablet) and ad unit sorted by earnings. Amounts are in the property currency. Use it to analyze monetization trends, RPM drops, and which countries, devices or ad units earn the most; combine with get-property-pages and get-property-snapshots to relate revenue to traffic.')]
#[IsReadOnly]
#[IsIdempotent]
class GetPropertyAdSenseTool extends Tool
{
    use ResolvesMcpContext;

    public function handle(Request $request, AdSenseReportService $reports): Response
    {
        $validated = $request->validate([
            'property_id' => 'required|integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'breakdown_limit' => 'nullable|integer|min:1|max:100',
            'include_daily' => 'nullable|boolean',
        ]);

        $property = $this->resolveAuthorizedProperty($validated['property_id']);

        if (! $reports->hasData($property)) {
            return Response::text(json_encode([
                'property' => $property->display_name,
                'message' => 'No AdSense data stored for this property. The Google account must be reconnected in Pulso to grant AdSense access, the property needs a website URL matching the AdSense site, and a sync must have run.',
            ], JSON_PRETTY_PRINT));
        }

        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : Carbon::today($property->timezone ?: 'UTC');
        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : $to->copy()->subDays(29);

        $report = $reports->summarize($property, $from, $to, $validated['breakdown_limit'] ?? 20);

        if (! ($validated['include_daily'] ?? true)) {
            unset($report['daily']);
        }

        return Response::text(json_encode([
            'property' => $property->display_name,
            'website_url' => $property->website_url,
            ...$report,
        ], JSON_PRETTY_PRINT));
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
            'from' => $schema->string()
                ->description('Start date (YYYY-MM-DD). Defaults to 29 days before "to" (a 30 day window).'),
            'to' => $schema->string()
                ->description('End date (YYYY-MM-DD). Defaults to today; today\'s earnings are partial.'),
            'breakdown_limit' => $schema->integer()
                ->description('Maximum rows per breakdown (country, platform, ad unit). Defaults to 20.'),
            'include_daily' => $schema->boolean()
                ->description('Include the per-day series. Defaults to true; set false for long ranges to keep the response small.'),
        ];
    }
}

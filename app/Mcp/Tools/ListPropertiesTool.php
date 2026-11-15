<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Models\GaProperty;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List all monitored websites with their latest GA4 snapshot trend and score. A property with has_analytics false is a Search Console only site: it has Search Console, Bing, index and annotation data but no GA4 snapshots, sources, pages, events or funnels.')]
#[IsReadOnly]
#[IsIdempotent]
class ListPropertiesTool extends Tool
{
    use ResolvesMcpContext;

    public function handle(Request $request): Response
    {
        $properties = $this->currentUser()->gaProperties()
            ->with(['snapshots' => function ($query) {
                $query->latest('snapshot_date')->limit(1);
            }])
            ->where('is_active', true)
            ->get();

        $data = $properties->map(function (GaProperty $property) {
            $latest = $property->snapshots->first();

            return [
                'id' => $property->id,
                'property_id' => $property->property_id,
                'has_analytics' => $property->hasAnalytics(),
                'search_console_site' => $property->gsc_site_url,
                'display_name' => $property->display_name,
                'website_url' => $property->website_url,
                'timezone' => $property->timezone,
                'latest_snapshot' => $latest ? [
                    'date' => $latest->snapshot_date->toDateString(),
                    'users' => $latest->users,
                    'sessions' => $latest->sessions,
                    'trend' => $latest->trend,
                    'trend_score' => $latest->trend_score,
                    'is_spike' => $latest->is_spike,
                    'is_drop' => $latest->is_drop,
                ] : null,
            ];
        });

        return Response::text(json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

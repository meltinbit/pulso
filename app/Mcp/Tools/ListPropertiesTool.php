<?php

namespace App\Mcp\Tools;

use App\Models\GaProperty;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List all GA4 properties with their latest snapshot trend and score. Use this to get an overview of all monitored websites.')]
#[IsReadOnly]
#[IsIdempotent]
class ListPropertiesTool extends Tool
{
    public function handle(Request $request): Response
    {
        $properties = GaProperty::with(['snapshots' => function ($query) {
            $query->latest('snapshot_date')->limit(1);
        }])->where('is_active', true)->get();

        $data = $properties->map(function (GaProperty $property) {
            $latest = $property->snapshots->first();

            return [
                'id' => $property->id,
                'property_id' => $property->property_id,
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

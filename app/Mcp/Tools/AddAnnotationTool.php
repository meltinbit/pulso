<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesMcpContext;
use App\Models\PropertyAnnotation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Record a change made to a site in its change log: new or rewritten content, a technical fix, a campaign, a deploy. Call it every time you publish or modify something on a site, with the day it went live, so later trend analysis can link rises and drops to their cause and avoid repeating experiments. Keep the description short and factual (what changed, not why it should work). Adding the same entry twice (same day, type and description) returns the existing one.')]
#[IsIdempotent]
class AddAnnotationTool extends Tool
{
    use ResolvesMcpContext;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => 'required|integer',
            'date' => 'nullable|date_format:Y-m-d',
            'type' => 'required|in:'.implode(',', PropertyAnnotation::TYPES),
            'description' => 'required|string|max:500',
            'urls' => 'nullable|array|max:20',
            'urls.*' => 'string|max:2048',
        ]);

        $property = $this->resolveAuthorizedProperty($validated['property_id']);
        $date = $validated['date'] ?? Carbon::today($property->timezone ?: 'UTC')->toDateString();
        $description = trim($validated['description']);

        $existing = $property->annotations()
            ->whereDate('date', $date)
            ->where('type', $validated['type'])
            ->where('description', $description)
            ->first();

        if ($existing) {
            return Response::text(json_encode(['duplicate' => true, 'annotation' => $existing->toCompactArray()]));
        }

        $urls = array_values(array_unique(array_map(
            fn (string $url): string => $this->absoluteUrl(trim($url), $property->website_url),
            $validated['urls'] ?? [],
        )));

        $annotation = $property->annotations()->create([
            'date' => $date,
            'type' => $validated['type'],
            'description' => $description,
            'urls' => $urls ?: null,
            'agent' => $this->currentAgent(),
        ]);

        return Response::text(json_encode(['created' => true, 'annotation' => $annotation->toCompactArray()]));
    }

    /**
     * Paths such as /blog/ become full URLs on the property website, the
     * same form the Search Console and Bing tools return.
     */
    private function absoluteUrl(string $url, ?string $websiteUrl): string
    {
        if (! str_starts_with($url, '/') || blank($websiteUrl)) {
            return $url;
        }

        $scheme = parse_url($websiteUrl, PHP_URL_SCHEME);
        $host = parse_url($websiteUrl, PHP_URL_HOST);

        return $scheme && $host ? "{$scheme}://{$host}{$url}" : $url;
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'property_id' => $schema->integer()
                ->description('The internal ID of the property (site). Use list-properties to find it.')
                ->required(),
            'date' => $schema->string()
                ->description('Day the change went live (YYYY-MM-DD). Defaults to today in the property timezone.'),
            'type' => $schema->string()
                ->enum(PropertyAnnotation::TYPES)
                ->description('content (new or rewritten pages), technical (fixes, speed, markup, redirects), campaign (ads, outreach, social), deploy (a release of the site).')
                ->required(),
            'description' => $schema->string()
                ->description('Short factual description, max 500 characters, e.g. "Rewritten title and intro of the IMU calculator".')
                ->required(),
            'urls' => $schema->array()
                ->items($schema->string())
                ->description('Pages involved, as full URLs or paths (/blog/), up to 20.'),
        ];
    }
}

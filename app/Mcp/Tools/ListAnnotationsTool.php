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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List the change log of a site (or of all sites when property_id is omitted) within a date range: day, type (content, technical, campaign, deploy), description, pages involved and the agent that wrote it. Check it before proposing an experiment, to avoid repeating one already made. Trend tools already include the annotations of their range.')]
#[IsReadOnly]
#[IsIdempotent]
class ListAnnotationsTool extends Tool
{
    use ResolvesMcpContext;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'property_id' => 'nullable|integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'type' => 'nullable|in:'.implode(',', PropertyAnnotation::TYPES),
            'contains' => 'nullable|string|max:255',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $from = Carbon::parse($validated['from'] ?? now()->subDays(90))->toDateString();
        $to = Carbon::parse($validated['to'] ?? now())->toDateString();
        $contains = filled($validated['contains'] ?? null) ? trim($validated['contains']) : null;

        $propertyIds = isset($validated['property_id'])
            ? [$this->resolveAuthorizedProperty($validated['property_id'])->id]
            : $this->currentUser()->gaProperties()->pluck('id')->all();

        $annotations = PropertyAnnotation::query()
            ->with('gaProperty:id,display_name')
            ->whereIn('ga_property_id', $propertyIds)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($validated['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            ->when($contains, function ($query, string $text) {
                $like = '%'.addcslashes($text, '%_\\').'%';

                $query->where(fn ($inner) => $inner->where('description', 'like', $like)->orWhere('urls', 'like', $like));
            })
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit($validated['limit'] ?? 50)
            ->get()
            ->map(fn (PropertyAnnotation $annotation): array => isset($validated['property_id'])
                ? $annotation->toCompactArray()
                : ['property' => $annotation->gaProperty->display_name] + $annotation->toCompactArray());

        return Response::text(json_encode([
            'from' => $from,
            'to' => $to,
            'count' => $annotations->count(),
            'annotations' => $annotations,
        ]));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'property_id' => $schema->integer()
                ->description('The internal ID of the property. Omit to list every site.'),
            'from' => $schema->string()
                ->description('Start date (YYYY-MM-DD). Defaults to 90 days ago.'),
            'to' => $schema->string()
                ->description('End date (YYYY-MM-DD). Defaults to today.'),
            'type' => $schema->string()
                ->enum(PropertyAnnotation::TYPES)
                ->description('Only this type of change.'),
            'contains' => $schema->string()
                ->description('Only entries whose description or URLs contain this text, e.g. "imu" or "/blog/".'),
            'limit' => $schema->integer()
                ->description('Maximum entries, newest first. Defaults to 50, maximum 200.'),
        ];
    }
}

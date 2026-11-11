<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\GaProperty;
use App\Models\PropertyAnnotation;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Adds the site's change log for the requested range to a trend tool, so the
 * agent sees causes (content, fixes, campaigns, deploys) next to the effects
 * in a single call.
 */
trait IncludesAnnotations
{
    /**
     * @return list<array<string, mixed>>
     */
    protected function annotationsBetween(GaProperty $property, CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        return $property->annotations()
            ->whereDate('date', '>=', Carbon::parse($from)->toDateString())
            ->whereDate('date', '<=', Carbon::parse($to)->toDateString())
            ->orderBy('date')
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn (PropertyAnnotation $annotation): array => $annotation->toCompactArray())
            ->all();
    }
}

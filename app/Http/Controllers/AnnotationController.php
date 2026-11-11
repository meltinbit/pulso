<?php

namespace App\Http\Controllers;

use App\Models\PropertyAnnotation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The sites' change log as the agents wrote it, to check it and remove
 * wrong entries.
 */
class AnnotationController extends Controller
{
    public function index(Request $request): Response
    {
        $properties = $request->user()->gaProperties()->orderBy('display_name')->get(['id', 'display_name']);
        $propertyId = $properties->contains('id', $request->integer('property')) ? $request->integer('property') : null;
        $type = in_array($request->query('type'), PropertyAnnotation::TYPES, true) ? $request->query('type') : null;

        $annotations = PropertyAnnotation::query()
            ->with('gaProperty:id,display_name')
            ->whereIn('ga_property_id', $propertyId ? [$propertyId] : $properties->pluck('id'))
            ->when($type, fn ($query, string $type) => $query->where('type', $type))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (PropertyAnnotation $annotation): array => [
                'id' => $annotation->id,
                'property' => $annotation->gaProperty->display_name,
                'date' => $annotation->date->toDateString(),
                'type' => $annotation->type,
                'description' => $annotation->description,
                'urls' => $annotation->urls ?? [],
                'agent' => $annotation->agent,
            ]);

        return Inertia::render('annotations/index', [
            'annotations' => $annotations,
            'properties' => $properties,
            'types' => PropertyAnnotation::TYPES,
            'filters' => ['property' => $propertyId, 'type' => $type],
        ]);
    }

    public function destroy(Request $request, PropertyAnnotation $annotation): RedirectResponse
    {
        abort_unless($annotation->gaProperty->user_id === $request->user()->id, 403);

        $annotation->delete();

        return back()->with('success', 'Annotazione eliminata.');
    }
}

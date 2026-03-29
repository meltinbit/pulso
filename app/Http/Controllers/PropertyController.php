<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePropertyRequest;
use App\Models\GaProperty;
use App\Services\GaPropertyDiscoveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PropertyController extends Controller
{
    public function index(Request $request, GaPropertyDiscoveryService $discovery): Response
    {
        $user = $request->user();
        $connections = $user->gaConnections()->where('is_active', true)->get();
        $savedProperties = $user->gaProperties()->with('gaConnection:id,google_email')->get();

        $available = $connections->flatMap(
            fn ($conn) => collect($discovery->listAccessibleProperties($conn))
                ->map(fn ($prop) => array_merge($prop, ['ga_connection_id' => $conn->id, 'connection_email' => $conn->google_email]))
        )->keyBy('property_id')->values();

        return Inertia::render('properties/index', [
            'available' => $available,
            'saved' => $savedProperties,
            'connections' => $connections->map->only('id', 'google_email', 'google_name'),
        ]);
    }

    public function store(StorePropertyRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        GaProperty::firstOrCreate([
            'user_id' => $request->user()->id,
            'property_id' => $validated['property_id'],
        ], [
            'ga_connection_id' => $validated['ga_connection_id'],
            'display_name' => $validated['display_name'],
            'website_url' => $validated['website_url'] ?? null,
            'timezone' => $validated['timezone'] ?? 'Europe/Rome',
            'currency' => $validated['currency'] ?? 'EUR',
        ]);

        return back()->with('success', 'Property aggiunta al monitoraggio.');
    }

    public function destroy(GaProperty $property): RedirectResponse
    {
        abort_unless($property->user_id === auth()->id(), 403);

        $property->delete();

        return back()->with('success', 'Property rimossa.');
    }

    public function switch(Request $request): RedirectResponse
    {
        $request->validate([
            'property_id' => [
                'required',
                Rule::exists('ga_properties', 'id')->where('user_id', $request->user()->id),
            ],
        ]);

        session(['active_property_id' => $request->property_id]);

        return back();
    }
}

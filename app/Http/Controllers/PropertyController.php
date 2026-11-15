<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePropertyRequest;
use App\Http\Requests\StoreSearchConsolePropertyRequest;
use App\Jobs\BackfillSearchConsoleData;
use App\Models\GaConnection;
use App\Models\GaProperty;
use App\Services\BingSyncService;
use App\Services\GaPropertyDiscoveryService;
use App\Services\SearchConsoleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class PropertyController extends Controller
{
    public function index(Request $request, GaPropertyDiscoveryService $discovery, SearchConsoleService $searchConsole): Response
    {
        $user = $request->user();
        $connections = $user->gaConnections()->where('is_active', true)->get();
        $savedProperties = $user->gaProperties()->with('gaConnection:id,google_email')->get();

        $available = $connections->flatMap(
            fn ($conn) => collect($discovery->listAccessibleProperties($conn))
                ->map(fn ($prop) => array_merge($prop, ['ga_connection_id' => $conn->id, 'connection_email' => $conn->google_email]))
        )->keyBy('property_id')->values();

        $savedHosts = $savedProperties->mapWithKeys(fn (GaProperty $property): array => [
            (string) BingSyncService::host($property->website_url) => $property->display_name,
        ]);

        $availableSites = $connections->flatMap(
            fn (GaConnection $conn) => collect($searchConsole->listSitesForConnection($conn))
                ->map(function (string $siteUrl) use ($conn, $savedHosts): array {
                    $websiteUrl = SearchConsoleService::websiteUrlFor($siteUrl);

                    return [
                        'site_url' => $siteUrl,
                        'website_url' => $websiteUrl,
                        'ga_connection_id' => $conn->id,
                        'connection_email' => $conn->google_email,
                        'covered_by' => $savedHosts[(string) BingSyncService::host($websiteUrl)] ?? null,
                    ];
                })
        )->unique('site_url')->sortBy('website_url')->values();

        return Inertia::render('properties/index', [
            'available' => $available,
            'availableSites' => $availableSites,
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

    /**
     * Add a Search Console site that has no GA4 property. The site must be in
     * the Search Console list of the chosen Google account.
     */
    public function storeSearchConsole(StoreSearchConsolePropertyRequest $request, SearchConsoleService $searchConsole): RedirectResponse
    {
        $validated = $request->validated();
        $connection = $request->user()->gaConnections()->findOrFail($validated['ga_connection_id']);
        $siteUrl = $validated['site_url'];

        if (! in_array($siteUrl, $searchConsole->listSitesForConnection($connection), true)) {
            return back()->with('error', "{$siteUrl} non risulta tra i siti Search Console di {$connection->google_email}.");
        }

        $websiteUrl = SearchConsoleService::websiteUrlFor($siteUrl);

        $property = GaProperty::firstOrCreate([
            'user_id' => $request->user()->id,
            'gsc_site_url' => $siteUrl,
        ], [
            'ga_connection_id' => $connection->id,
            'property_id' => null,
            'display_name' => (string) BingSyncService::host($websiteUrl),
            'website_url' => $websiteUrl,
        ]);

        if ($property->wasRecentlyCreated) {
            BackfillSearchConsoleData::dispatch($property);
        }

        return back()->with('success', 'Sito Search Console aggiunto: lo storico di 16 mesi si scarica in background.');
    }

    public function update(Request $request, GaProperty $property): RedirectResponse
    {
        abort_unless($property->user_id === auth()->id(), 403);

        $validated = $request->validate([
            'website_url' => ['nullable', 'url', 'max:255'],
        ]);

        $property->update($validated);

        return back()->with('success', 'Property aggiornata.');
    }

    public function destroy(GaProperty $property): RedirectResponse
    {
        abort_unless($property->user_id === auth()->id(), 403);

        $property->delete();

        return back()->with('success', 'Property rimossa.');
    }

    public function switch(Request $request): RedirectResponse|SymfonyResponse
    {
        $request->validate([
            'property_id' => [
                'required',
                Rule::exists('ga_properties', 'id')->where('user_id', $request->user()->id),
            ],
        ]);

        $request->user()->forceFill(['active_property_id' => $request->integer('property_id')])->save();

        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return back();
    }
}

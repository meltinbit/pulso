<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\GaProperty;
use App\Services\SettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SnapshotSettingsController extends Controller
{
    public function __construct(
        private SettingService $settings,
    ) {}

    public function edit(Request $request): Response
    {
        $userId = $request->user()->id;

        $properties = $request->user()->gaProperties()
            ->select('id', 'display_name', 'website_url', 'is_active')
            ->orderBy('display_name')
            ->get();

        return Inertia::render('settings/snapshots', [
            'settings' => [
                'snapshot_enabled' => $this->settings->get($userId, 'snapshot_enabled', '1'),
                'snapshot_time' => $this->settings->get($userId, 'snapshot_time', '09:00'),
                'snapshot_telegram' => $this->settings->get($userId, 'snapshot_telegram', '1'),
            ],
            'properties' => $properties,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'snapshot_enabled' => ['required', 'in:0,1'],
            'snapshot_time' => ['required', 'date_format:H:i'],
            'snapshot_telegram' => ['required', 'in:0,1'],
            'active_properties' => ['present', 'array'],
            'active_properties.*' => ['integer', 'exists:ga_properties,id'],
        ]);

        $userId = $request->user()->id;

        $this->settings->set($userId, 'snapshot_enabled', $validated['snapshot_enabled'], 'snapshots');
        $this->settings->set($userId, 'snapshot_time', $validated['snapshot_time'], 'snapshots');
        $this->settings->set($userId, 'snapshot_telegram', $validated['snapshot_telegram'], 'snapshots');

        $userPropertyIds = $request->user()->gaProperties()->pluck('id');

        GaProperty::whereIn('id', $userPropertyIds)->update(['is_active' => false]);
        GaProperty::whereIn('id', $validated['active_properties'])
            ->whereIn('id', $userPropertyIds)
            ->update(['is_active' => true]);

        return back()->with('success', 'Impostazioni snapshot aggiornate.');
    }
}
